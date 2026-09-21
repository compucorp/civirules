<?php

/**
 * Tests deferring post triggers out of the transaction destructor.
 *
 * @group headless
 */
class CRM_Civirules_DeferredPostTriggerTest extends BaseHeadlessTest {

  public function setUp(): void {
    parent::setUp();
    $GLOBALS['civirules_deferred_post_triggers'] = [];
    $GLOBALS['civirules_deferred_drain_registered'] = FALSE;
    CRM_Civirules_ThrowingTestTrigger::$calls = [];
  }

  public function tearDown(): void {
    unset($GLOBALS['civirules_deferred_post_triggers'], $GLOBALS['civirules_deferred_drain_registered']);
    parent::tearDown();
  }

  /**
   * Skips the destructor-detection tests where the restriction does not exist.
   */
  private function requireFiberRestriction() {
    if (PHP_VERSION_ID >= 80400) {
      $this->markTestSkipped('PHP 8.4+ allows fibers in destructors, so nothing defers.');
    }
  }

  public function testDoesNotDeferOutsideADestructor() {
    $this->assertFalse(civirules_defer_post_triggers());
  }

  /**
   * The reason this code exists: a destructor anywhere on the stack must be
   * detected, however deep. A real failure had the destructor at frame 30.
   */
  public function testDefersInsideADestructor() {
    $this->requireFiberRestriction();

    $seen = NULL;
    $probe = new CRM_Civirules_DestructorProbe(function () use (&$seen) {
      $seen = civirules_defer_post_triggers();
    });
    unset($probe);

    $this->assertTrue($seen, 'A destructor on the stack must be detected.');
  }

  /**
   * Detection must survive the destructor being far down the stack, not just
   * the immediate caller. A depth-limited backtrace would fail this.
   */
  public function testDefersWhenTheDestructorIsDeepInTheStack() {
    $this->requireFiberRestriction();

    $seen = NULL;
    $probe = new CRM_Civirules_DestructorProbe(function () use (&$seen) {
      $seen = $this->recurse(40, function () {
        return civirules_defer_post_triggers();
      });
    });
    unset($probe);

    $this->assertTrue($seen, 'Detection must not depend on how deep the destructor sits.');
  }

  public function testRunsInlineOutsideADestructor() {
    civirules_call_post_trigger_on_commit('create', '', 1, NULL, 1);

    $this->assertSame([], $GLOBALS['civirules_deferred_post_triggers'],
      'Ordinary context must not queue anything.');
    $this->assertFalse($GLOBALS['civirules_deferred_drain_registered']);
  }

  public function testQueuesInsideADestructor() {
    $this->requireFiberRestriction();

    $probe = new CRM_Civirules_DestructorProbe(function () {
      civirules_call_post_trigger_on_commit('create', 'TestDeferEntity', 7, NULL, 1);
    });
    unset($probe);

    $this->assertCount(1, $GLOBALS['civirules_deferred_post_triggers']);
    $this->assertSame(
      ['create', 'TestDeferEntity', 7, NULL, 1],
      $GLOBALS['civirules_deferred_post_triggers'][0]
    );
    $this->assertTrue($GLOBALS['civirules_deferred_drain_registered']);
  }

  /**
   * An Error from one trigger must not abandon the ones queued behind it.
   *
   * civirules_call_post_trigger() catches Exception, so only an Error gets
   * this far, which is exactly the class of failure this feature exists for.
   */
  public function testDrainRunsRemainingTriggersAfterOneThrows() {
    $this->registerThrowingTrigger();

    $GLOBALS['civirules_deferred_post_triggers'] = [
      ['create', 'TestDeferEntity', 1, NULL, 1],
      ['create', 'TestDeferEntity', 2, NULL, 1],
    ];

    civirules_drain_post_triggers();

    $this->assertSame([1, 2], CRM_Civirules_ThrowingTestTrigger::$calls,
      'The second trigger must still run after the first throws.');
    $this->assertSame([], $GLOBALS['civirules_deferred_post_triggers'],
      'The queue must be fully consumed.');
  }

  /**
   * Cleared so a shutdown function registered after this one can queue more
   * triggers and have them drained rather than silently dropped.
   */
  public function testDrainClearsTheRegisteredFlag() {
    $GLOBALS['civirules_deferred_drain_registered'] = TRUE;

    civirules_drain_post_triggers();

    $this->assertFalse($GLOBALS['civirules_deferred_drain_registered']);
  }

  /**
   * Installs a rule whose trigger throws on its first invocation.
   */
  private function registerThrowingTrigger() {
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_trigger (name, label, object_name, op, cron, class_name, is_active)
       VALUES ('test_defer_trigger', 'Test defer trigger', 'TestDeferEntity', 'create', 0, %1, 1)",
      [1 => [CRM_Civirules_ThrowingTestTrigger::class, 'String']]
    );
    $triggerId = CRM_Core_DAO::singleValueQuery('SELECT LAST_INSERT_ID()');

    CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule (name, label, trigger_id, is_active)
       VALUES ('test_defer_rule', 'Test defer rule', %1, 1)",
      [1 => [$triggerId, 'Integer']]
    );
  }

  /**
   * Calls $fn $depth frames down.
   */
  private function recurse($depth, callable $fn) {
    if ($depth <= 0) {
      return $fn();
    }
    return $this->recurse($depth - 1, $fn);
  }

}

/**
 * Runs a callback from inside a destructor.
 */
class CRM_Civirules_DestructorProbe {

  /**
   * @var callable
   */
  private $fn;

  public function __construct(callable $fn) {
    $this->fn = $fn;
  }

  public function __destruct() {
    ($this->fn)();
  }

}

/**
 * A post trigger that throws an Error the first time it runs.
 */
class CRM_Civirules_ThrowingTestTrigger extends CRM_Civirules_Trigger_Post {

  /**
   * Object ids this trigger was invoked for, in order.
   *
   * @var array
   */
  public static $calls = [];

  public function triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID) {
    self::$calls[] = $objectId;
    if (count(self::$calls) === 1) {
      throw new Error('Deliberate Error from a deferred trigger.');
    }
  }

}
