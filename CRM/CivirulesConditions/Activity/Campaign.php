<?php
/**
 * Class for CiviRule Condition FirstContribution
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 3 May 2018
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_CivirulesConditions_Activity_Campaign extends CRM_Civirules_Condition {

  public function getExtraDataInputUrl($ruleConditionId) {
    return $this->getFormattedExtraDataInputUrl('civicrm/civirule/form/condition/activity/campaign', $ruleConditionId);
  }

  /**
   * Returns condition data as an array and ready for export.
   * E.g. replace ids for names.
   *
   * @return array
   */
  public function exportConditionParameters() {
    $params = parent::exportConditionParameters();
    if (!empty($params['campaign_id'])) {
      try {
        $params['campaign_id'] = civicrm_api3('Campaign', 'getvalue', [
          'return' => 'name',
          'id' => $params['campaign_id'],
        ]);
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
    if (!empty($condition_params['campaign_id'])) {
      try {
        $condition_params['campaign_id'] = civicrm_api3('Campaign', 'getvalue', [
          'return' => 'id',
          'name' => $condition_params['campaign_id'],
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
    if (!isset($activityData['campaign_id'])) {
      try {
        $campaignId = civicrm_api3('Activity', 'getvalue', [
          'id' => $activityData['id'],
          'return' => 'campaign_id',
        ]);
      }
      catch (CRM_Core_Exception $ex) {
        $campaignId = NULL;
      }
    }
    else {
      $campaignId = $activityData['campaign_id'];
    }
    switch ($this->conditionParams['operator']) {
      case 0:
        if (in_array($campaignId, $this->conditionParams['campaign_id'])) {
          $isConditionValid = TRUE;
        }
        break;

      case 1:
        if (!in_array($campaignId, $this->conditionParams['campaign_id'])) {
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
      $friendlyText = 'Campaign is one of: ';
    }
    if ($this->conditionParams['operator'] == 1) {
      $friendlyText = 'Campaign is NOT one of: ';
    }
    $campaignTitles = [];
    foreach ($this->conditionParams['campaign_id'] as $campaignId) {
      try {
        $campaignTitles[] = civicrm_api3('Campaign', 'getvalue', [
          'id' => $campaignId,
          'return' => 'title',
        ]);
      }
      catch (CRM_Core_Exception $ex) {
      }
    }
    if (!empty($campaignTitles)) {
      $friendlyText .= implode(", ", $campaignTitles);
    }
    else {
      $friendlyText .= implode(', ', $this->conditionParams['campaign_id']);
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
