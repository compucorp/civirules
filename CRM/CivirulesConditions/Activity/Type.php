<?php

use Civi\Api4\Activity;

/**
 * Class for CiviRule Condition FirstContribution
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_CivirulesConditions_Activity_Type extends CRM_Civirules_Condition {

  public function getExtraDataInputUrl($ruleConditionId) {
    return $this->getFormattedExtraDataInputUrl('civicrm/civirule/form/condition/activity_type', $ruleConditionId);
  }

  /**
   * Returns condition data as an array and ready for export.
   * E.g. replace ids for names.
   *
   * @return array
   */
  public function exportConditionParameters() {
    $params = parent::exportConditionParameters();
    if (!empty($params['activity_type_id'])) {
      try {
        $optionValues = civicrm_api4('OptionValue', 'get', [
          'select' => [
            'name',
          ],
          'where' => [
            ['value', 'IN', $params['activity_type_id']],
            ['option_group_id:name', '=', 'activity_type'],
          ],
          'checkPermissions' => FALSE,
        ]);
        $params['activity_type_id'] = array_column(iterator_to_array($optionValues), 'name');
      }
      catch (\CRM_Core_Exception $e) {
        // Do nothing.
      }
    }
    return $params;
  }

  /**
   * Returns condition data as an array and ready for import.
   * E.g. replace name for ids.
   *
   * @return string
   */
  public function importConditionParameters($condition_params = NULL) {
    if (!empty($condition_params['activity_type_id'])) {
      try {
        $condition_params['activity_type_id'] = civicrm_api3('OptionValue', 'getvalue', [
          'return' => 'value',
          'name' => $condition_params['activity_type_id'],
          'option_group_id' => 'activity_type',
        ]);
      }
      catch (\CRM_Core_Exception $e) {
        // Do nothing.
      }
    }
    return parent::importConditionParameters($condition_params);
  }

  /**
   * Method to check if the condition is valid, will check if the contact
   * has an activity of the selected type
   *
   * @param object CRM_Civirules_TriggerData_TriggerData $triggerData
   * @return bool
   * @access public
   */
  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $isConditionValid = FALSE;
    $activityData = $triggerData->getEntityData('Activity');

    if (empty($activityData['activity_type_id'] ?? '')) {
      $activity = Activity::get(FALSE)
        ->addSelect('activity_type_id')
        ->addWhere('id', '=', $activityData['id'])
        ->setLimit(1)
        ->execute()
        ->first();
      $activityData['activity_type_id'] = $activity['activity_type_id'];
      $triggerData->setEntityData('Activity', $activityData);
    }

    switch ($this->conditionParams['operator']) {
      case 0:
        if (in_array($activityData['activity_type_id'], $this->conditionParams['activity_type_id'] ?? [])) {
          $isConditionValid = TRUE;
        }
        break;

      case 1:
        if (!in_array($activityData['activity_type_id'], $this->conditionParams['activity_type_id']) ?? []) {
          $isConditionValid = TRUE;
        }
        break;
    }
    return $isConditionValid;
  }

  /**
   * Returns a user friendly text explaining the condition params
   * e.g. 'Older than 65'
   *
   * @return string
   * @access public
   */
  public function userFriendlyConditionParams() {
    $friendlyText = "";
    if ($this->conditionParams['operator'] == 0) {
      $friendlyText = 'Activity Type is one of: ';
    }
    if ($this->conditionParams['operator'] == 1) {
      $friendlyText = 'Activity Type is NOT one of: ';
    }
    $actText = [];
    foreach ($this->conditionParams['activity_type_id'] as $actTypeId) {
      $actText[] = civicrm_api3('OptionValue', 'getvalue', [
        'option_group_id' => 'activity_type',
        'value' => $actTypeId,
        'return' => 'label',
      ]);
    }
    if (!empty($actText)) {
      $friendlyText .= implode(", ", $actText);
    }
    return $friendlyText;
  }

  /**
   * This function validates whether this condition works with the selected trigger.
   *
   * This function could be overriden in child classes to provide additional validation
   * whether a condition is possible in the current setup. E.g. we could have a condition
   * which works on contribution or on contributionRecur then this function could do
   * this kind of validation and return false/true
   *
   * @param CRM_Civirules_Trigger $trigger
   * @param CRM_Civirules_BAO_Rule $rule
   * @return bool
   */
  public function doesWorkWithTrigger(CRM_Civirules_Trigger $trigger, CRM_Civirules_BAO_Rule $rule) {
    return $trigger->doesProvideEntity('Activity');
  }

}
