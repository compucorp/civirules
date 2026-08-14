<?php

/**
 * Test fixtures for PostCrossClassCacheTest.php.
 *
 * Kept in a separate file, required lazily at runtime (not at the top of
 * the test file) because they extend a CiviCRM class: PHPUnit's suite
 * loader parses test files before CiviCRM has booted, and "extends"
 * resolves its parent at class-declaration time.
 */

/**
 * Stands in for a trigger class whose data ends up cached and handed to a
 * later, different trigger class for the same event - e.g.
 * CRM_CivirulesPostTrigger_Activity, which does not attach anything
 * beyond the base entity.
 */
class CRM_Civirules_Trigger_PostCrossClassCacheTest_PlainFixture extends CRM_Civirules_Trigger_Post {

  public static array $seen = [];

  public function triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID) {
    parent::triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID);
    self::$seen[] = $this->getTriggerData()->getEntityData('Marker');
  }

}

/**
 * Stands in for a trigger class that attaches extra entity data in
 * getTriggerDataFromPost() - e.g. CRM_CivirulesPostTrigger_CaseActivity,
 * which appends a Case entity on top of the base Activity one.
 */
class CRM_Civirules_Trigger_PostCrossClassCacheTest_MarkerFixture extends CRM_Civirules_Trigger_Post {

  public static array $seen = [];

  protected function getTriggerDataFromPost($op, $objectName, $objectId, $objectRef, $eventID = NULL) {
    $triggerData = parent::getTriggerDataFromPost($op, $objectName, $objectId, $objectRef, $eventID);
    $triggerData->setEntityData('Marker', ['flag' => 1]);
    return $triggerData;
  }

  public function triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID) {
    parent::triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID);
    self::$seen[] = $this->getTriggerData()->getEntityData('Marker');
  }

}
