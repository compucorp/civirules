<?php

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;

/**
 * Tests a trigger committed from a transaction destructor inside a fiber.
 *
 * Not transactional on purpose: the test wraps everything in a transaction,
 * so nothing would ever commit and no PHASE_POST_COMMIT callback would run.
 * Each test removes the rows it adds.
 *
 * @group headless
 */
class CRM_Civirules_DeferredPostTriggerFiberTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/DeferredPostTriggerTestFixtures.php';

    if (PHP_VERSION_ID >= 80400) {
      $this->markTestSkipped('PHP 8.4+ allows switching fibers in destructors, so nothing defers.');
    }

    CRM_Civirules_DeferredPostTriggerTest_FiberFixture::$seen = [];
    $GLOBALS['civirules_deferred_post_triggers'] = [];
    $GLOBALS['civirules_deferred_drain_registered'] = FALSE;

    $triggerId = CiviRulesTrigger::create(FALSE)
      ->setValues([
        'name' => 'phpunit_deferred_fiber',
        'label' => 'PHPUnit deferred fiber trigger',
        'object_name' => 'Tag',
        'op' => 'create',
        'class_name' => CRM_Civirules_DeferredPostTriggerTest_FiberFixture::class,
        'cron' => FALSE,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
    CiviRulesRule::create(FALSE)
      ->setValues([
        'name' => 'phpunit_deferred_fiber_rule',
        'label' => 'PHPUnit deferred fiber rule',
        'trigger_id' => $triggerId,
        'is_active' => TRUE,
      ])
      ->execute();
  }

  public function tearDown(): void {
    unset($GLOBALS['civirules_deferred_post_triggers'], $GLOBALS['civirules_deferred_drain_registered']);
    CRM_Core_DAO::executeQuery("DELETE FROM civicrm_tag WHERE name LIKE 'phpunit_deferred_%'");
    CRM_Core_DAO::executeQuery("DELETE FROM civirule_rule WHERE name = 'phpunit_deferred_fiber_rule'");
    CRM_Core_DAO::executeQuery("DELETE FROM civirule_trigger WHERE name = 'phpunit_deferred_fiber'");
    parent::tearDown();
  }

  /**
   * Runs $fn inside a fiber and returns whatever it threw.
   */
  private function inFiber(callable $fn): ?\Throwable {
    $error = NULL;
    $fiber = new Fiber(function () use ($fn, &$error) {
      try {
        $fn();
      }
      catch (\Throwable $e) {
        $error = $e;
      }
    });
    $fiber->start();
    return $error;
  }

  /**
   * A transaction that commits from its own destructor inside a fiber.
   *
   * The fixture starts a nested fiber, as a CMS renderer does. Without the
   * deferral that throws FiberError out of the destructor.
   */
  public function testTriggerCommittedFromADestructorInsideAFiber(): void {
    $error = $this->inFiber(function () {
      $tx = new CRM_Core_Transaction();
      civirules_instanciate_post_trigger('create', 'Tag', 1, NULL, 1);
      // Commits from CRM_Core_Transaction::__destruct().
      unset($tx);

      $this->assertSame([], CRM_Civirules_DeferredPostTriggerTest_FiberFixture::$seen,
        'The trigger must wait until the destructor has returned.');
      civirules_flush_deferred_post_triggers();
    });

    $this->assertNull($error, 'No FiberError: ' . ($error ? get_class($error) . ': ' . $error->getMessage() : ''));
    $this->assertSame([[1, 'suspended']], CRM_Civirules_DeferredPostTriggerTest_FiberFixture::$seen);
  }

  /**
   * The API layer commits from a destructor when it responds. The trigger
   * must still have run by the time the API call returns.
   */
  public function testTriggerRunsBeforeTheApiCallReturns(): void {
    $seenWhenReturned = NULL;
    $error = $this->inFiber(function () use (&$seenWhenReturned) {
      civicrm_api3('Tag', 'create', ['name' => 'phpunit_deferred_' . uniqid()]);
      $seenWhenReturned = count(CRM_Civirules_DeferredPostTriggerTest_FiberFixture::$seen);
    });

    $this->assertNull($error, 'No FiberError: ' . ($error ? get_class($error) . ': ' . $error->getMessage() : ''));
    $this->assertSame(1, $seenWhenReturned, 'The trigger must run before the API call returns, not at shutdown.');
    $this->assertSame([], $GLOBALS['civirules_deferred_post_triggers']);
  }

  /**
   * A save with no transaction open can still run in a destructor inside a
   * fiber: a POST_COMMIT callback runs there after the frame has closed. The
   * trigger must be queued, and the callbacks after it must still run.
   */
  public function testSaveWithNoTransactionFromADestructorInsideAFiberIsQueued(): void {
    $activeInCallback = NULL;
    $queuedAfterCommit = NULL;
    $laterCallbackRan = FALSE;
    $error = $this->inFiber(function () use (&$activeInCallback, &$queuedAfterCommit, &$laterCallbackRan) {
      $tx = new CRM_Core_Transaction();
      CRM_Core_Transaction::addCallback(CRM_Core_Transaction::PHASE_POST_COMMIT, function () use (&$activeInCallback) {
        $activeInCallback = CRM_Core_Transaction::isActive();
        civirules_instanciate_post_trigger('create', 'Tag', 1, NULL, 1);
      }, []);
      CRM_Core_Transaction::addCallback(CRM_Core_Transaction::PHASE_POST_COMMIT, function () use (&$laterCallbackRan) {
        $laterCallbackRan = TRUE;
      }, []);
      // Commits from CRM_Core_Transaction::__destruct().
      unset($tx);

      $queuedAfterCommit = count($GLOBALS['civirules_deferred_post_triggers']);
      civirules_flush_deferred_post_triggers();
    });

    $this->assertNull($error, 'No FiberError: ' . ($error ? get_class($error) . ': ' . $error->getMessage() : ''));
    $this->assertFalse($activeInCallback, 'No transaction is open in a POST_COMMIT callback.');
    $this->assertSame(1, $queuedAfterCommit, 'The trigger must be queued, not run inside the destructor.');
    $this->assertTrue($laterCallbackRan, 'Later POST_COMMIT callbacks must still run.');
    $this->assertSame([[1, 'suspended']], CRM_Civirules_DeferredPostTriggerTest_FiberFixture::$seen);
  }

  /**
   * APIv4 opens no transaction of its own, but the trigger must still have
   * run by the time the API call returns.
   */
  public function testTriggerRunsBeforeTheApiv4CallReturns(): void {
    $seenWhenReturned = NULL;
    $error = $this->inFiber(function () use (&$seenWhenReturned) {
      \Civi\Api4\Tag::create(FALSE)
        ->addValue('name', 'phpunit_deferred_' . uniqid())
        ->execute();
      $seenWhenReturned = count(CRM_Civirules_DeferredPostTriggerTest_FiberFixture::$seen);
    });

    $this->assertNull($error, 'No FiberError: ' . ($error ? get_class($error) . ': ' . $error->getMessage() : ''));
    $this->assertSame(1, $seenWhenReturned, 'The trigger must run before the API call returns, not at shutdown.');
    $this->assertSame([], $GLOBALS['civirules_deferred_post_triggers']);
  }

}
