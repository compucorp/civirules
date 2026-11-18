<?php

/**
 * Fixtures for CRM_Civirules_DeferredPostTriggerTest.
 *
 * Kept out of the test file and required lazily: this extends a CiviCRM
 * class, and PHPUnit's suite loader parses test files before CiviCRM has
 * booted.
 */

/**
 * A post trigger that throws an Error the first time it runs.
 *
 * An Error rather than an Exception on purpose: civirules_call_post_trigger()
 * swallows Exception, so only an Error reaches the drain.
 */
class CRM_Civirules_DeferredPostTriggerTest_ThrowingFixture extends CRM_Civirules_Trigger_Post {

  /**
   * Object ids this trigger was invoked for, in order.
   *
   * @var array
   */
  public static $seen = [];

  public function triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID) {
    self::$seen[] = $objectId;
    if (count(self::$seen) === 1) {
      throw new Error('Deliberate Error from a deferred trigger.');
    }
  }

}

/**
 * A post trigger that records the pre data it sees for its object.
 */
class CRM_Civirules_DeferredPostTriggerTest_RecordingFixture extends CRM_Civirules_Trigger_Post {

  /**
   * Object id and pre data per run, in order.
   *
   * @var array
   */
  public static $seen = [];

  public function triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID) {
    self::$seen[] = [$objectId, CRM_Civirules_Utils_PreData::getPreData('Contact', $objectId, $eventID)];
  }

}

/**
 * A post trigger that starts a nested fiber, as a CMS renderer does.
 *
 * Below PHP 8.4 that throws FiberError when run from a destructor.
 */
class CRM_Civirules_DeferredPostTriggerTest_FiberFixture extends CRM_Civirules_Trigger_Post {

  /**
   * Object id and the value the nested fiber suspended with, per run.
   *
   * @var array
   */
  public static $seen = [];

  public function triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID) {
    $fiber = new Fiber(function () {
      Fiber::suspend('suspended');
    });
    self::$seen[] = [$objectId, $fiber->start()];
  }

}

/**
 * The GroupContact trigger, recording the status of each row it sees.
 */
class CRM_Civirules_DeferredPostTriggerTest_GroupContactFixture extends CRM_CivirulesPostTrigger_GroupContact {

  /**
   * Group contact statuses seen, in order.
   *
   * @var array
   */
  public static $statuses = [];

  protected function getTriggerDataFromPost($op, $objectName, $objectId, $objectRef, $eventID = NULL) {
    self::$statuses[] = is_array($objectRef) ? $objectRef['status'] : $objectRef->status;
    return parent::getTriggerDataFromPost($op, $objectName, $objectId, $objectRef, $eventID);
  }

}
