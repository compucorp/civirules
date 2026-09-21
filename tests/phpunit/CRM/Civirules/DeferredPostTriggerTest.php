<?php

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests deferring post triggers out of the transaction destructor.
 *
 * CiviCRM fires PHASE_POST_COMMIT callbacks from
 * CRM_Core_Transaction::__destruct(). Below PHP 8.4 a Fiber cannot be started
 * there, and a CMS permission check can start one, so a rule whose condition
 * or action reaches the CMS throws FiberError. Deferring the work to shutdown,
 * which is ordinary execution context, avoids it.
 *
 * @group headless
 */
class CRM_Civirules_DeferredPostTriggerTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    // Required lazily, not at the top of this file: the fixture extends a
    // CiviCRM class, and PHPUnit's suite loader parses test files before
    // CiviCRM has booted.
    require_once __DIR__ . '/DeferredPostTriggerTestFixtures.php';

    CRM_Civirules_DeferredPostTriggerTest_ThrowingFixture::$seen = [];
    $GLOBALS['civirules_deferred_post_triggers'] = [];
    $GLOBALS['civirules_deferred_drain_registered'] = FALSE;
  }

  public function tearDown(): void {
    unset($GLOBALS['civirules_deferred_post_triggers'], $GLOBALS['civirules_deferred_drain_registered']);
    parent::tearDown();
  }

  /**
   * Skips where the restriction being worked around does not exist.
   */
  private function requireFiberRestriction(): void {
    if (PHP_VERSION_ID >= 80400) {
      $this->markTestSkipped('PHP 8.4+ allows switching fibers in destructors, so nothing defers.');
    }
  }

  /**
   * Runs $fn inside an object destructor.
   */
  private function inDestructor(callable $fn) {
    $result = NULL;
    $probe = new class($fn, $result) {

      private $fn;
      private $result;

      public function __construct(callable $fn, &$result) {
        $this->fn = $fn;
        $this->result = &$result;
      }

      public function __destruct() {
        $this->result = ($this->fn)();
      }

    };
    unset($probe);
    return $result;
  }

  /**
   * Calls $fn $depth frames further down the stack.
   */
  private function recurse(int $depth, callable $fn) {
    return $depth <= 0 ? $fn() : $this->recurse($depth - 1, $fn);
  }

  public function testDoesNotDeferOutsideADestructor(): void {
    $this->assertFalse(civirules_defer_post_triggers());
  }

  public function testDefersInsideADestructor(): void {
    $this->requireFiberRestriction();

    $this->assertTrue(
      $this->inDestructor('civirules_defer_post_triggers'),
      'A destructor anywhere on the stack must be detected.'
    );
  }

  /**
   * Detection must not depend on how deep the destructor sits. A real failure
   * had CRM_Core_Transaction::__destruct() at frame 30, so a depth-limited
   * backtrace would miss it and silently stop deferring.
   */
  public function testDefersWhenTheDestructorIsDeepInTheStack(): void {
    $this->requireFiberRestriction();

    $seen = $this->inDestructor(function () {
      return $this->recurse(40, 'civirules_defer_post_triggers');
    });

    $this->assertTrue($seen, 'Detection must survive a deep stack.');
  }

  public function testRunsInlineOutsideADestructor(): void {
    civirules_call_post_trigger_on_commit('create', '', 1, NULL, NULL);

    $this->assertSame([], $GLOBALS['civirules_deferred_post_triggers'],
      'Ordinary context must run inline, not queue.');
    $this->assertFalse($GLOBALS['civirules_deferred_drain_registered']);
  }

  public function testQueuesInsideADestructor(): void {
    $this->requireFiberRestriction();

    $this->inDestructor(function () {
      civirules_call_post_trigger_on_commit('create', 'Contact', 7, NULL, NULL);
      return NULL;
    });

    $this->assertSame(
      [['create', 'Contact', 7, NULL, NULL]],
      $GLOBALS['civirules_deferred_post_triggers']
    );
    $this->assertTrue($GLOBALS['civirules_deferred_drain_registered']);
  }

  /**
   * An Error from one trigger must not abandon the ones queued behind it.
   */
  public function testDrainRunsRemainingTriggersAfterOneThrows(): void {
    $this->createRuleFor(CRM_Civirules_DeferredPostTriggerTest_ThrowingFixture::class);

    $GLOBALS['civirules_deferred_post_triggers'] = [
      ['create', 'Contact', 1, NULL, NULL],
      ['create', 'Contact', 2, NULL, NULL],
    ];

    civirules_drain_post_triggers();

    $this->assertSame([1, 2], CRM_Civirules_DeferredPostTriggerTest_ThrowingFixture::$seen,
      'The second trigger must still run after the first throws.');
    $this->assertSame([], $GLOBALS['civirules_deferred_post_triggers'],
      'The queue must be fully consumed.');
  }

  /**
   * Cleared so a shutdown function registered after this one can queue further
   * triggers and have them drained rather than silently dropped.
   */
  public function testDrainClearsTheRegisteredFlag(): void {
    $GLOBALS['civirules_deferred_drain_registered'] = TRUE;

    civirules_drain_post_triggers();

    $this->assertFalse($GLOBALS['civirules_deferred_drain_registered']);
  }

  /**
   * Installs an active rule whose trigger is the given class.
   */
  private function createRuleFor(string $triggerClass): int {
    $triggerId = CiviRulesTrigger::create(FALSE)
      ->setValues([
        'name' => 'phpunit_deferred_' . md5($triggerClass),
        'label' => 'PHPUnit deferred trigger',
        'object_name' => 'Contact',
        'op' => 'create',
        'class_name' => $triggerClass,
        'cron' => FALSE,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];

    return CiviRulesRule::create(FALSE)
      ->setValues([
        'name' => 'phpunit_deferred_rule_' . md5($triggerClass),
        'label' => 'PHPUnit deferred rule',
        'trigger_id' => $triggerId,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
  }

}
