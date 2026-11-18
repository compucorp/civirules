<?php

require_once 'civirules.civix.php';
if (!interface_exists("\\Psr\\Log\\LoggerInterface")) {
  require_once('psr/log/LoggerInterface.php');
}
if (!class_exists("\\Psr\\Log\\LogLevel")) {
  require_once('psr/log/LogLevel.php');
}

use Civi\Core\ClassScanner;
use CRM_Civirules_ExtensionUtil as E;

/**
 * Hook to add the symfony event listeners.
 *
 * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
 *
 * @throws \CRM_Core_Exception
 */
function civirules_civicrm_container(\Symfony\Component\DependencyInjection\ContainerBuilder $container) {
  $container->addCompilerPass(new \Civi\ConfigItems\CiviRulesCompilerPass());
  // Add the symfony listeners.
  // We need to eventID parameter to prevent overwriting of original data in case we
  // have a rule based on edit activity and action to edit a second activity.
  // See this PR: https://lab.civicrm.org/extensions/civirules/-/merge_requests/96
  $container->findDefinition('dispatcher')
    ->addMethodCall('addListener', [
      'civi.dao.preInsert',
      'civirules_trigger_preinsert'
    ])
    ->addMethodCall('addListener', [
      'civi.dao.postInsert',
      'civirules_trigger_postinsert'
    ])
    ->addMethodCall('addListener', [
      'civi.dao.preUpdate',
      'civirules_trigger_preupdate'
    ])
    ->addMethodCall('addListener', [
      'civi.dao.postUpdate',
      'civirules_trigger_postupdate'
    ])
    ->addMethodCall('addListener', [
      'civi.dao.preDelete',
      'civirules_trigger_predelete'
    ])
    ->addMethodCall('addListener', [
      'civi.dao.postDelete',
      'civirules_trigger_postdelete',
    ])
    // Runs at the end of every API call. APIv3 create, delete and submit
    // commit their own transaction at W_MIDDLE, before this. The flush checks
    // that no transaction is still open.
    ->addMethodCall('addListener', [
      'civi.api.respond',
      'civirules_flush_deferred_post_triggers',
      \Civi\API\Events::W_LATE,
    ]);
}

/**
 * Implementation of hook_civicrm_config
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function civirules_civicrm_config(&$config) {
  _civirules_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_install
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function civirules_civicrm_install() {
  _civirules_civix_civicrm_install();
}

/**
 * Implementation of hook_civicrm_enable
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function civirules_civicrm_enable() {
  _civirules_civix_civicrm_enable();
}

/**
 * Implementation of hook_civicrm_managed
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function civirules_civicrm_managed(&$entities) {
  // First create a backup because the managed entities are gone
  // so the actions and conditions table are first going to be emptied
  _civirules_upgrade_to_2x_backup();
  // Check triggers, actions and conditions
  CRM_Civirules_Utils_Upgrader::insertTriggersFromJson(E::path('sql/triggers.json'));
  CRM_Civirules_Utils_Upgrader::insertActionsFromJson(E::path('sql/actions.json'));
  CRM_Civirules_Utils_Upgrader::insertConditionsFromJson(E::path('sql/conditions.json'));
}

/**
 * Helper function to create a backup if the current schema version is of a 1.x version.
 * We need this backup to restore missing actions and rules after upgrading.
 */
function _civirules_upgrade_to_2x_backup() {
  // Check schema version
  // Schema version 1023 is inserted by a 2x version
  // So if the schema version is lower than 1023 we are still on a 1x version.
  // If empty, we are installing
  $schemaVersion = CRM_Core_DAO::singleValueQuery("SELECT schema_version FROM civicrm_extension WHERE `name` = 'CiviRules'");
  if ($schemaVersion >= 1023 || empty($schemaVersion)) {
    return; // No need for preparing the update.
  }

  if (!CRM_Core_DAO::checkTableExists('civirule_rule_action_backup')) {
    // Backup the current action and condition connected to a civirule
    CRM_Core_DAO::executeQuery("
      CREATE TABLE `civirule_rule_action_backup`
      SELECT `civirule_rule_action`.*, `civirule_action`.`class_name` as `action_class_name`
      FROM `civirule_rule_action`
      INNER JOIN `civirule_action` ON `civirule_rule_action`.`action_id` = `civirule_action`.`id`
    ");
  }
  if (!CRM_Core_DAO::checkTableExists('civirule_rule_action_backup')) {
    CRM_Core_DAO::executeQuery("
      CREATE TABLE `civirule_rule_condition_backup`
      SELECT `civirule_rule_condition`.*, `civirule_condition`.`class_name` as `condition_class_name`
      FROM `civirule_rule_condition`
      INNER JOIN `civirule_condition` ON `civirule_rule_condition`.`condition_id` = `civirule_condition`.`id`
    ");
  }
}

/**
 * By default we use the Symfony event for preInsert, preUpdate, postInsert etc.
 * However there a couple of entities which do not work yet with the symfony events.
 *
 * In the future this should be refactored so that this would be simplified
 * so that the inner workings are unambiguously with symfony events but that also depends
 * on CiviCRM core changes.
 *
 * @param $op
 * @param $objectName
 * @param $objectId
 * @param $params
 */
function civirules_civicrm_pre($op, $objectName, $objectId, &$params) {
  CRM_Civirules_Utils_ContributionTrigger::pre($op, $objectName, $objectId, $params);
  // New style pre/post Delete/Insert/Update events exist from 5.34.
  if (civirules_use_prehook($op, $objectName, $objectId, $params)) {
    try {
      CRM_Civirules_Utils_PreData::pre($op, $objectName, $objectId, $params, 1);
      CRM_Civirules_Utils_CustomDataFromPre::pre($op, $objectName, $objectId, $params, 1);
    } catch (\Exception $ex) {
      // Do nothing.
    }
  }
}

/**
 * By default we use the Symfony event for preInsert, preUpdate, postInsert etc.
 * However there a couple of entities which do not work yet with the symfony events.
 *
 * In the future this should be refactored so that this would be simplified
 * so that the inner workings are unambiguously with symfony events but that also depends
 * on CiviCRM core changes.
 *
 * @param $op
 * @param $objectName
 * @param $objectId
 * @param $objectRef
 */
function civirules_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if (civirules_use_posthook($op, $objectName, $objectId, $objectRef)) {
    civirules_instanciate_post_trigger($op, $objectName, $objectId, $objectRef, 1);
  }
}

/**
 * Function to check whether the pre hook could be called from a symfony
 * preInsert, preUpdate, preDelete event. Or whether this should be called from
 * the traditional hook_civicrm_pre.
 *
 * @param $op
 * @param $objectName
 * @param $objectId
 * @param $params
 *
 * @return bool
 */
function civirules_use_prehook($op, $objectName, $objectId, &$params) {
  if ($objectName == 'GroupContact') {
    return TRUE;
  }
  return FALSE;
}

/**
 * Function to check whether the post trigger could be called from a symfony
 * postInsert, postUpdate, postDelete event. Or whether this should be called from
 * the traditional hook_civicrm_post.
 *
 * @param $op
 * @param $objectName
 * @param $objectId
 * @param $objectRef
 *
 * @return bool
 */
function civirules_use_posthook($op, $objectName, $objectId, &$objectRef) {
  if ($objectName == 'GroupContact' && is_array($objectRef)) {
    // GroupContact with the objectRef as an array of contact ids does
    // call hook_civicrm_post directly and does not invoke a civicrm event.
    return TRUE;
  }
  return FALSE;
}

/**
 * This event is called before an entity is inserted in the database.
 *
 * @param \Civi\Core\DAO\Event\PreUpdate $event
 */
function civirules_trigger_preinsert(\Civi\Core\DAO\Event\PreUpdate $event) {
  try {
    $objectName = CRM_Civirules_Utils::getObjectNameFromObject($event->object);
    $objectId = $event->object->id;
    $eventID = $event->eventID ?? 1;
    $params = [];
    CRM_Core_DAO::storeValues($event->object, $params);
    CRM_Civirules_Utils_PreData::pre('create', $objectName, $objectId, $params, $eventID);
    CRM_Civirules_Utils_CustomDataFromPre::pre('create', $objectName, $objectId, $params, $eventID);
  } catch (\Exception $ex) {
    // Do nothing.
  }
}

/**
 * This event is called after an entity is inserted in the database.
 *
 * @param $event
 */
function civirules_trigger_postinsert($event) {
  $objectName = CRM_Civirules_Utils::getObjectNameFromObject($event->object);
  $eventID = $event->eventID ?? 1;
  if (!civirules_use_posthook('create', $objectName, $event->object->id, $event->object)) {
    civirules_instanciate_post_trigger('create', $objectName, $event->object->id, $event->object, $eventID);
  }
}

/**
 * This event is called before an entity is updated in the database.
 *
 * @param \Civi\Core\DAO\Event\PreUpdate $event
 */
function civirules_trigger_preupdate(\Civi\Core\DAO\Event\PreUpdate $event) {
  try {
    $objectName = CRM_Civirules_Utils::getObjectNameFromObject($event->object);
    $objectId = $event->object->id;
    $eventID = $event->eventID ?? 1;
    $params = [];
    CRM_Core_DAO::storeValues($event->object, $params);
    CRM_Civirules_Utils_PreData::pre('edit', $objectName, $objectId, $params, $eventID);
    CRM_Civirules_Utils_CustomDataFromPre::pre('edit', $objectName, $objectId, $params, $eventID);
  } catch (\Exception $ex) {
    // Do nothing.
  }
}

/**
 * This event is called after an entity is updated in the database.
 *
 * @param $event
 */
function civirules_trigger_postupdate($event) {
  $objectName = CRM_Civirules_Utils::getObjectNameFromObject($event->object);
  $eventID = $event->eventID ?? 1;
  if (!civirules_use_posthook('edit', $objectName, $event->object->id, $event->object)) {
    civirules_instanciate_post_trigger('edit', $objectName, $event->object->id, $event->object, $eventID);
  }
}

/**
 * This event is called before an entity is deleted.
 *
 * @param \Civi\Core\DAO\Event\PreDelete $event
 */
function civirules_trigger_predelete(\Civi\Core\DAO\Event\PreDelete $event) {
  try {
    $objectName = CRM_Civirules_Utils::getObjectNameFromObject($event->object);
    $objectId = $event->object->id;
    $eventID = $event->eventID ?? 1;
    $params = [];
    CRM_Core_DAO::storeValues($event->object, $params);
    CRM_Civirules_Utils_PreData::pre('delete', $objectName, $objectId, $params, $eventID);
    CRM_Civirules_Utils_CustomDataFromPre::pre('delete', $objectName, $objectId, $params, $eventID);
  } catch (\Exception $ex) {
    // Do nothing.
  }
}

/**
 * This event is called after an entity is deleted.
 *
 * @param $event
 */
function civirules_trigger_postdelete($event) {
  $objectName = CRM_Civirules_Utils::getObjectNameFromObject($event->object);
  $eventID = $event->eventID ?? 1;
  if (!civirules_use_posthook('delete', $objectName, $event->object->id, $event->object)) {
    civirules_instanciate_post_trigger('delete', $objectName, $event->object->id, $event->object, $eventID);
  }
}

/**
 * Function to call the post trigger.
 * It either creates a callback when a transaction is active or it calls triggers directly.
 *
 * @param $op
 * @param $objectName
 * @param $objectId
 * @param $objectRef
 * @param $eventId
 */
function civirules_instanciate_post_trigger($op, $objectName, $objectId, $objectRef, $eventId) {
  try {
    if (CRM_Core_Transaction::isActive()) {
      CRM_Core_Transaction::addCallback(CRM_Core_Transaction::PHASE_POST_COMMIT, 'civirules_call_post_trigger_on_commit', [
        $op,
        $objectName,
        $objectId,
        $objectRef,
        $eventId
      ]);
    }
    else {
      // This can still be a destructor inside a fiber, such as a save made by
      // a POST_COMMIT callback, so it may have to be queued too.
      civirules_call_post_trigger_on_commit($op, $objectName, $objectId, $objectRef, $eventId);
    }
  } catch (\Exception $ex) {
    // Do nothing.
  }
}

/**
 * Runs a post trigger, or queues it from a destructor inside a fiber.
 *
 * CiviCRM fires PHASE_POST_COMMIT callbacks from
 * CRM_Core_Transaction::__destruct(). Below PHP 8.4 a Fiber cannot be started
 * from a destructor, and a CMS permission check can start one, so a rule whose
 * condition or action reaches the CMS throws FiberError. That extends Error
 * rather than Exception, so the catch below never sees it and the request dies
 * after the data has already committed. Queue those and run them at the first
 * safe point: once the API call has committed, before the next trigger runs,
 * or at shutdown as the last resort.
 *
 * The queued trigger keeps a copy of the object and of the data it reads, as
 * they are now, so a later save in the same request cannot change what it sees.
 *
 * Only the destructor path defers, so every caller already running in ordinary
 * context keeps firing inline and in order.
 *
 * @param $op
 * @param $objectName
 * @param $objectId
 * @param $objectRef
 * @param $eventID
 */
function civirules_call_post_trigger_on_commit($op, $objectName, $objectId, $objectRef, $eventID) {
  if (!civirules_defer_post_triggers()) {
    // Anything queued earlier runs first, so triggers keep their order.
    civirules_run_deferred_post_triggers();
    civirules_call_post_trigger($op, $objectName, $objectId, $objectRef, $eventID);
    return;
  }

  $GLOBALS['civirules_deferred_post_triggers'][] = [
    $op,
    $objectName,
    $objectId,
    is_object($objectRef) ? clone $objectRef : $objectRef,
    $eventID,
    civirules_snapshot_post_trigger($op, $objectName, $objectId, $objectRef, $eventID),
  ];
  if (empty($GLOBALS['civirules_deferred_drain_registered'])) {
    $GLOBALS['civirules_deferred_drain_registered'] = TRUE;
    register_shutdown_function('civirules_drain_post_triggers');
  }
}

/**
 * Captures the data a deferred trigger reads, as it is when queued.
 *
 * Pre data from hook_civicrm_pre is always stored under event 1, so a later
 * save of the same record (trashing or restoring a contact, say) replaces it.
 * The GroupContact trigger reads its rows from the database when it runs.
 * Only the pre data of the entities the trigger reads is captured.
 *
 * @param $op
 * @param $objectName
 * @param $objectId
 * @param $objectRef
 * @param $eventID
 *
 * @return array
 */
function civirules_snapshot_post_trigger($op, $objectName, $objectId, $objectRef, $eventID) {
  $preDataEntities = CRM_Civirules_Utils_PreData::entitiesReadBy($objectName);
  $snapshot = [
    'preData' => CRM_Civirules_Utils_PreData::snapshot($objectId, $eventID, $preDataEntities),
  ];
  if ($objectName === 'GroupContact' && is_array($objectRef) && CRM_Civirules_BAO_CiviRulesRule::findRulesByObjectNameAndOp($objectName, $op)) {
    $snapshot['groupContacts'] = CRM_CivirulesPostTrigger_GroupContact::findGroupContacts((int) $objectId, $objectRef);
  }
  return $snapshot;
}

/**
 * Whether the trigger has to be moved out of the current execution context.
 *
 * PHP 8.4 allows fibers in destructors, so above it nothing needs deferring.
 *
 * @return bool
 *   TRUE when a fiber is running, a destructor is on the stack, and this PHP
 *   forbids starting a fiber there.
 */
function civirules_defer_post_triggers() {
  if (PHP_VERSION_ID >= 80400) {
    return FALSE;
  }
  // A CMS only starts the nested fiber that fails when one is already running,
  // so outside a fiber there is nothing to avoid. This also keeps long running
  // CLI processes firing triggers inline rather than queueing them until the
  // process ends.
  if (\Fiber::getCurrent() === NULL) {
    return FALSE;
  }
  foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
    if (isset($frame['function']) && $frame['function'] === '__destruct') {
      return TRUE;
    }
  }
  return FALSE;
}

/**
 * Runs the deferred post triggers if this is a safe point to do so.
 *
 * Not inside an open transaction, where a rollback would undo the actions of
 * a trigger whose data has already committed, and not where deferring is
 * still needed.
 *
 * @param object|null $event
 *   The API respond event when called as a listener. Not used.
 */
function civirules_flush_deferred_post_triggers($event = NULL) {
  if (empty($GLOBALS['civirules_deferred_post_triggers'])
    || CRM_Core_Transaction::isActive()
    || civirules_defer_post_triggers()) {
    return;
  }
  civirules_run_deferred_post_triggers();
}

/**
 * Runs the post triggers that were deferred out of a transaction destructor.
 *
 * Only for register_shutdown_function(), the last resort when no earlier
 * safe point came.
 *
 * Clears the registered flag at the end so that a shutdown function
 * registered after this one can queue further triggers and have them
 * drained.
 */
function civirules_drain_post_triggers() {
  // A drain still marked as running here was cut short by an exit or fatal
  // error, or sits in a suspended fiber, and is not going to finish.
  $GLOBALS['civirules_deferred_running'] = FALSE;
  if (CRM_Core_Transaction::isActive()) {
    // A fatal error inside a transaction leaves it open, so it will not
    // commit. Actions run now would roll back while their emails still go
    // out. Leave the queue as the flush does; clearing it gains nothing.
    if (!empty($GLOBALS['civirules_deferred_post_triggers'])) {
      $message = sprintf('CiviRules: %d deferred post triggers not run, a transaction is still open at shutdown.', count($GLOBALS['civirules_deferred_post_triggers']));
      try {
        Civi::log()->warning($message);
      }
      catch (\Throwable $loggerFailure) {
        error_log($message);
      }
    }
    $GLOBALS['civirules_deferred_drain_registered'] = FALSE;
    return;
  }
  civirules_run_deferred_post_triggers();
  $GLOBALS['civirules_deferred_drain_registered'] = FALSE;
}

/**
 * Runs the queued triggers in order until the queue is empty.
 *
 * Drains in a loop rather than a foreach because an action can commit its own
 * transaction and queue further triggers while this is running. A nested call
 * returns at once and leaves those to the outer loop, so the order holds.
 * That guard is a global, not a static, so the shutdown drain can reset it.
 *
 * Catches Throwable rather than Exception. civirules_call_post_trigger()
 * already swallows Exception, so only an Error can reach here, and letting one
 * escape would abandon every trigger still queued behind it.
 */
function civirules_run_deferred_post_triggers() {
  if (!empty($GLOBALS['civirules_deferred_running'])) {
    return;
  }
  $GLOBALS['civirules_deferred_running'] = TRUE;
  try {
    while (!empty($GLOBALS['civirules_deferred_post_triggers'])) {
      $args = array_shift($GLOBALS['civirules_deferred_post_triggers']);
      try {
        civirules_run_deferred_post_trigger($args);
      }
      catch (\Throwable $e) {
        // The logger itself can fail at shutdown, and Civi may not even be
        // loaded. Either way that must not abandon the rest of the queue.
        $message = 'CiviRules: deferred post trigger failed: ' . $e->getMessage();
        try {
          Civi::log()->error($message, ['exception' => $e]);
        }
        catch (\Throwable $loggerFailure) {
          error_log($message);
        }
      }
    }
  }
  finally {
    $GLOBALS['civirules_deferred_running'] = FALSE;
  }
}

/**
 * Runs one queued trigger against the data captured when it was queued.
 *
 * @param array $args
 *   The queued op, object name, object id, object, event id and snapshot.
 */
function civirules_run_deferred_post_trigger(array $args) {
  [$op, $objectName, $objectId, $objectRef, $eventID] = $args;
  $snapshot = $args[5] ?? [];

  $preDataEntities = CRM_Civirules_Utils_PreData::entitiesReadBy($objectName);
  $laterPreData = CRM_Civirules_Utils_PreData::restore($objectId, $eventID, $preDataEntities, $snapshot['preData'] ?? []);
  $laterGroupContacts = CRM_CivirulesPostTrigger_GroupContact::$groupContactsAsQueued;
  if (isset($snapshot['groupContacts'])) {
    CRM_CivirulesPostTrigger_GroupContact::$groupContactsAsQueued[$objectId] = $snapshot['groupContacts'];
  }
  try {
    civirules_call_post_trigger($op, $objectName, $objectId, $objectRef, $eventID);
  }
  finally {
    // Put back what later saves stored, for their own triggers.
    CRM_Civirules_Utils_PreData::restore($objectId, $eventID, $preDataEntities, $laterPreData);
    CRM_CivirulesPostTrigger_GroupContact::$groupContactsAsQueued = $laterGroupContacts;
  }
}

/**
 * This callback is called from the post hooks or after the transaction is completed.
 *
 * @param $op
 * @param $objectName
 * @param $objectId
 * @param $objectRef
 * @param $eventID
 */
function civirules_call_post_trigger($op, $objectName, $objectId, $objectRef, $eventID) {
  try {
    CRM_Civirules_Trigger_Post::post($op, $objectName, $objectId, $objectRef, $eventID);
  } catch (\Exception $ex) {
    // Do nothing.
  }
}

/**
 * This is the pre hook before custom data has been changed.
 *
 * @param $op
 * @param $groupID
 * @param $entityID
 * @param $params
 */
function civirules_civicrm_customPre($op, $groupID, $entityID, &$params) {
  try {
    CRM_Civirules_Utils_PreData::customPre($op, $groupID, $entityID, $params, 1);
  } catch (\Exception $ex) {
    // Do nothing.
  }
}

/**
 * This is the post hook after custom data has been changed.
 *
 * @param $op
 * @param $groupID
 * @param $entityID
 * @param $params
 *
 * @throws \CRM_Core_Exception
 */
function civirules_civicrm_custom($op, $groupID, $entityID, &$params) {
  /**
   * Fix/Hack for issue #208 (https://github.com/CiviCooP/org.civicoop.civirules/issues/208)
   *
   * To reproduce:
   * - create a custom data set for contacts that supports multiple records
   * - create a rule that triggers on custom data changing
   * - add a record to that custom data set for a contact
   * - delete the record
   * - observe the logs
   *
   * This returns the error: "Expected one Contact but found 25"
   * Traced to CRM/CivirulesPostTrigger/ContactCustomDataChanged.php where there is an api call to contacts getsingle. The issue is that when the custom data record is deleted, there is no remaining entity_id with which to retrieve the contact, and so no id is passed to the getsingle call.
   *
   * The fix is to check whether the $op is delete and whether $entityID is empty and then check
   * whether the contactID is provided in the url.
   */
  if ($op == 'delete' && empty($entityID)) {
    $contactId = CRM_Utils_Request::retrieve('contactId', 'Positive');
    if (!empty($contactId)) {
      $entityID = $contactId;
    }
  }
  /** End ugly hack */

  CRM_CivirulesPostTrigger_CaseCustomDataChanged::custom($op, $groupID, $entityID, $params);
  CRM_CivirulesPostTrigger_ContactCustomDataChanged::custom($op, $groupID, $entityID, $params);
  CRM_CivirulesPostTrigger_IndividualCustomDataChanged::custom($op, $groupID, $entityID, $params);
  CRM_CivirulesPostTrigger_OrganizationCustomDataChanged::custom($op, $groupID, $entityID, $params);
  CRM_CivirulesPostTrigger_HouseholdCustomDataChanged::custom($op, $groupID, $entityID, $params);
}

function civirules_civirules_alter_trigger_data(CRM_Civirules_TriggerData_TriggerData &$triggerData) {
  //also add the custom data which is passed to the pre hook (and not the post)
  CRM_Civirules_Utils_CustomDataFromPre::addCustomDataToTriggerData($triggerData);
}

/**
 * Implements hook_civicrm_apiWrappers()
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_apiWrappers/
 */
function civirules_civicrm_apiWrappers(&$wrappers, $apiRequest) {
  if ($apiRequest['entity'] == 'Contact' && $apiRequest['action'] == 'create') {
    $wrappers[] = new CRM_Civirules_TrashRestoreApiWrapper();
  }
}

/**
 * Implements hook_civicrm_permission().
 */
function civirules_civicrm_permission(&$permissions) {
  $permissions['administer CiviRules'] = [
    'label' => E::ts('CiviRules: administer CiviRules extension'),
    'description' => E::ts('Perform all CiviRules administration tasks in CiviCRM'),
  ];
}

/**
 * We can't use mixin scan-classes directly because we need to exclude the ConfigItems classes
 *   since that will fail if ConfigItems extension is not installed because scan-classes
 *   picks them up automatically.
 *
 * @param $classes
 *
 * @return void
 */
function civirules_civicrm_scanClasses(&$classes) {
  $cache = ClassScanner::cache('structure');
  $cacheKey = E::LONG_NAME;
  $all = $cache->get($cacheKey);
  if ($all === NULL) {
    $baseDir = CRM_Utils_File::addTrailingSlash(E::path());
    $all = [];

    ClassScanner::scanFolders($all, $baseDir, 'CRM', '_');
    ClassScanner::scanFolders($all, $baseDir, 'Civi', '\\', ';(ConfigItems);');
    $cache->set($cacheKey, $all, ClassScanner::TTL);
  }

  $classes = array_merge($classes, $all);
}
