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
    CRM_Civirules_DeferredPostTriggerTest_RecordingFixture::$seen = [];
    CRM_Civirules_DeferredPostTriggerTest_GroupContactFixture::$statuses = [];
    CRM_Civirules_DeferredPostTriggerTest_GroupContactFixture::$contactIds = [];
    CRM_Civirules_DeferredPostTriggerTest_GroupContactFixture::$onFirstRow = NULL;
    $GLOBALS['civirules_deferred_post_triggers'] = [];
    $GLOBALS['civirules_deferred_drain_registered'] = FALSE;
    $GLOBALS['civirules_deferred_running'] = FALSE;
  }

  public function tearDown(): void {
    unset($GLOBALS['civirules_deferred_post_triggers'], $GLOBALS['civirules_deferred_drain_registered'], $GLOBALS['civirules_deferred_running']);
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
   * Runs $fn inside an object destructor that is itself inside a fiber.
   *
   * Both halves matter. A CMS only starts the nested fiber that fails when one
   * is already running, and PHP only refuses to start it inside a destructor.
   */
  private function inDestructorInsideFiber(callable $fn) {
    $result = NULL;
    $fiber = new Fiber(function () use ($fn, &$result) {
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
    });
    $fiber->start();
    return $result;
  }

  /**
   * Runs $fn inside an object destructor with no fiber running.
   */
  private function inDestructorOnly(callable $fn) {
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
      $this->inDestructorInsideFiber('civirules_defer_post_triggers'),
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

    $seen = $this->inDestructorInsideFiber(function () {
      return $this->recurse(40, 'civirules_defer_post_triggers');
    });

    $this->assertTrue($seen, 'Detection must survive a deep stack.');
  }

  /**
   * A destructor with no fiber running is the CLI and cron shape. Deferring
   * there would queue triggers until the process ended, which for a long
   * running command means they never fire and the queue grows unbounded.
   */
  public function testDoesNotDeferWhenNoFiberIsRunning(): void {
    $this->requireFiberRestriction();

    $this->assertFalse(
      $this->inDestructorOnly('civirules_defer_post_triggers'),
      'With no fiber running there is no nested fiber to avoid.'
    );
  }

  public function testRunsInlineOutsideADestructor(): void {
    civirules_call_post_trigger_on_commit('create', '', 1, NULL, NULL);

    $this->assertSame([], $GLOBALS['civirules_deferred_post_triggers'],
      'Ordinary context must run inline, not queue.');
    $this->assertFalse($GLOBALS['civirules_deferred_drain_registered']);
  }

  public function testQueuesInsideADestructor(): void {
    $this->requireFiberRestriction();

    $this->inDestructorInsideFiber(function () {
      civirules_call_post_trigger_on_commit('create', 'Contact', 7, NULL, NULL);
      return NULL;
    });

    $this->assertCount(1, $GLOBALS['civirules_deferred_post_triggers']);
    $this->assertSame(
      ['create', 'Contact', 7, NULL, NULL],
      array_slice($GLOBALS['civirules_deferred_post_triggers'][0], 0, 5)
    );
    $this->assertTrue($GLOBALS['civirules_deferred_drain_registered']);
  }

  /**
   * A trigger that runs inline must not overtake one queued before it.
   */
  public function testQueuedTriggersRunBeforeTheNextTrigger(): void {
    $this->requireFiberRestriction();
    $this->createRuleFor(CRM_Civirules_DeferredPostTriggerTest_RecordingFixture::class);

    $this->inDestructorInsideFiber(function () {
      civirules_call_post_trigger_on_commit('create', 'Contact', 1, NULL, 1);
      return NULL;
    });
    civirules_call_post_trigger_on_commit('create', 'Contact', 2, NULL, 1);

    $this->assertSame([1, 2], array_column(CRM_Civirules_DeferredPostTriggerTest_RecordingFixture::$seen, 0));
    $this->assertSame([], $GLOBALS['civirules_deferred_post_triggers']);
  }

  /**
   * Running inside an open transaction would let a rollback undo the actions
   * of a trigger whose data has already committed.
   */
  public function testFlushWaitsWhileATransactionIsOpen(): void {
    $this->assertTrue(CRM_Core_Transaction::isActive(), 'The test runs inside a transaction.');
    $GLOBALS['civirules_deferred_post_triggers'] = [['create', 'Contact', 1, NULL, 1, []]];

    civirules_flush_deferred_post_triggers();

    $this->assertCount(1, $GLOBALS['civirules_deferred_post_triggers']);
  }

  /**
   * Pre data from hook_civicrm_pre is always stored under event 1. Trashing a
   * contact and then restoring it in the same request replaces it before the
   * deferred trash trigger runs.
   */
  public function testDeferredTriggerSeesThePreDataFromWhenItWasQueued(): void {
    $this->requireFiberRestriction();
    $this->createRuleFor(CRM_Civirules_DeferredPostTriggerTest_RecordingFixture::class, 'Contact', 'edit');

    CRM_Civirules_Utils_PreData::restore(7, 1, ['Contact' => ['id' => 7, 'is_deleted' => 0]]);
    $this->inDestructorInsideFiber(function () {
      civirules_call_post_trigger_on_commit('edit', 'Contact', 7, NULL, 1);
      return NULL;
    });
    // A later save of the same contact, here the restore.
    CRM_Civirules_Utils_PreData::restore(7, 1, ['Contact' => ['id' => 7, 'is_deleted' => 1]]);

    civirules_run_deferred_post_triggers();

    $this->assertSame(
      [[7, ['id' => 7, 'is_deleted' => 0]]],
      CRM_Civirules_DeferredPostTriggerTest_RecordingFixture::$seen,
      'The deferred trigger must see the pre data from when it was queued.'
    );
    $this->assertSame(['id' => 7, 'is_deleted' => 1], CRM_Civirules_Utils_PreData::getPreData('Contact', 7, 1),
      'The later save keeps its own pre data for its own trigger.');
  }

  /**
   * The GroupContact trigger reads its rows from the database, so a removal
   * later in the same request would show the added contact as removed.
   */
  public function testDeferredGroupContactTriggerSeesTheRowsFromWhenItWasQueued(): void {
    $this->requireFiberRestriction();
    $this->createRuleFor(CRM_Civirules_DeferredPostTriggerTest_GroupContactFixture::class, 'GroupContact', 'create');

    $contactId = civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Deferred'])['id'];
    $groupId = civicrm_api3('Group', 'create', ['title' => 'PHPUnit deferred ' . uniqid()])['id'];
    CRM_Contact_BAO_GroupContact::addContactsToGroup([$contactId], $groupId);

    $this->inDestructorInsideFiber(function () use ($groupId, $contactId) {
      civirules_call_post_trigger_on_commit('create', 'GroupContact', $groupId, [$contactId], 1);
      return NULL;
    });
    // A later save of the same membership.
    CRM_Core_DAO::executeQuery("UPDATE civicrm_group_contact SET status = 'Removed' WHERE group_id = %1 AND contact_id = %2", [
      1 => [$groupId, 'Integer'],
      2 => [$contactId, 'Integer'],
    ]);

    civirules_run_deferred_post_triggers();

    $this->assertSame(['Added'], CRM_Civirules_DeferredPostTriggerTest_GroupContactFixture::$statuses);
    $this->assertSame([], CRM_CivirulesPostTrigger_GroupContact::$groupContactsAsQueued,
      'The rows apply to the deferred run only.');
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

    civirules_run_deferred_post_triggers();

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
   * A GroupContact post for the same group, fired by an action of the deferred
   * trigger, must read its own rows rather than the ones queued for the outer
   * trigger.
   */
  public function testNestedGroupContactTriggerReadsItsOwnRows(): void {
    $this->requireFiberRestriction();
    $this->createRuleFor(CRM_Civirules_DeferredPostTriggerTest_GroupContactFixture::class, 'GroupContact', 'create');

    $queuedContactId = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Queued'])['id'];
    $nestedContactId = (int) civicrm_api3('Contact', 'create', ['contact_type' => 'Individual', 'first_name' => 'Nested'])['id'];
    $groupId = civicrm_api3('Group', 'create', ['title' => 'PHPUnit deferred ' . uniqid()])['id'];
    CRM_Contact_BAO_GroupContact::addContactsToGroup([$queuedContactId], $groupId);

    $this->inDestructorInsideFiber(function () use ($groupId, $queuedContactId) {
      civirules_call_post_trigger_on_commit('create', 'GroupContact', $groupId, [$queuedContactId], 1);
      return NULL;
    });
    // An action of the deferred trigger adds another contact to the same group.
    CRM_Civirules_DeferredPostTriggerTest_GroupContactFixture::$onFirstRow = function () use ($groupId, $nestedContactId) {
      CRM_Contact_BAO_GroupContact::addContactsToGroup([$nestedContactId], $groupId);
      civirules_call_post_trigger('create', 'GroupContact', $groupId, [$nestedContactId], 1);
    };

    civirules_run_deferred_post_triggers();

    $this->assertSame([$queuedContactId, $nestedContactId], CRM_Civirules_DeferredPostTriggerTest_GroupContactFixture::$contactIds);
  }

  /**
   * The shutdown drain does not run triggers inside an open transaction.
   *
   * A transaction still open at shutdown will not commit, so their actions
   * would roll back while their emails still go out.
   */
  public function testShutdownDrainDoesNotRunInsideAnOpenTransaction(): void {
    $this->createRuleFor(CRM_Civirules_DeferredPostTriggerTest_RecordingFixture::class);
    $this->assertTrue(CRM_Core_Transaction::isActive(), 'The test runs inside a transaction.');
    $GLOBALS['civirules_deferred_post_triggers'] = [['create', 'Contact', 1, NULL, 1, []]];

    civirules_drain_post_triggers();

    $this->assertSame([], CRM_Civirules_DeferredPostTriggerTest_RecordingFixture::$seen);
    $this->assertCount(1, $GLOBALS['civirules_deferred_post_triggers'], 'The queue is left as it is.');
  }

  /**
   * Installs an active rule whose trigger is the given class.
   */
  private function createRuleFor(string $triggerClass, string $objectName = 'Contact', string $op = 'create'): int {
    $triggerId = CiviRulesTrigger::create(FALSE)
      ->setValues([
        'name' => 'phpunit_deferred_' . md5($triggerClass),
        'label' => 'PHPUnit deferred trigger',
        'object_name' => $objectName,
        'op' => $op,
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
