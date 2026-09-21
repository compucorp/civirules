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
