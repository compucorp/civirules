<?php

/**
 * Class CRM_Civirules_Utils_HookInvoker
 *
 * This class invokes hooks through the civicrm core hook invoker functionality
 */
class CRM_Civirules_Utils_HookInvoker {

  private static $singleton;

  private function __construct() {

  }

  public static function singleton() {
    if (!self::$singleton) {
      self::$singleton = new CRM_Civirules_Utils_HookInvoker();
    }
    return self::$singleton;
  }

  /**
   * hook_civirules_alter_delay_classes
   *
   * @param array $classes
   *
   * This hook could alter the classes with options for a delay
   */
  public function hook_civirules_alter_delay_classes(array $classes) {
    $null = NULL;
    CRM_Utils_Hook::singleton()->invoke(
      ['classes'],
      $classes,
      $null,
      $null,
      $null,
      $null,
      $null,
      'civirules_alter_delay_classes'
    );
  }

  /**
   * hook_civicrm_civirules_logger
   *
   * @param \Psr\Log\LoggerInterface|NULL $logger
   *
   * This hook could set a logger class for Civirules
   */
  public function hook_civirules_getlogger(&$logger = NULL) {
    $null = NULL;
    CRM_Utils_Hook::singleton()->invoke(
      ['logger'],
      $logger,
      $null,
      $null,
      $null,
      $null,
      $null,
      'civirules_logger'
    );
    if ($logger && !$logger instanceof \Psr\Log\LoggerInterface) {
      $logger = NULL;
    }
  }

  /**
   * @param \CRM_Civirules_TriggerData_TriggerData $triggerData
   *
   * @return void
   */
  public function hook_civirules_alterTriggerData(CRM_Civirules_TriggerData_TriggerData &$triggerData) {
    $null = NULL;
    CRM_Utils_Hook::singleton()->invoke(
      ['triggerData'],
      $triggerData,
      $null,
      $null,
      $null,
      $null,
      $null,
      'civirules_alter_trigger_data'
    );
  }

  /**
   * Lets extensions alter/remove entries from the list of triggers offered
   * on the "Select Trigger" dropdown when adding a new rule. $triggerList is
   * indexed by civirule_trigger.id, each entry carrying the full row
   * (id, label, name, class_name, object_name, op).
   *
   * @param array $triggerList
   *
   * @return void
   */
  public function hook_civirules_alterTriggerList(array &$triggerList) {
    $null = NULL;
    CRM_Utils_Hook::singleton()->invoke(
      ['triggerList'],
      $triggerList,
      $null,
      $null,
      $null,
      $null,
      $null,
      'civirules_alter_trigger_list'
    );
  }

}
