<?php
/**
 * Class for CiviRules Condition Has Activity of Type(s) in Campaign(s)
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @date 25 April 2018
 * @license AGPL-3.0
 */
class CRM_CivirulesConditions_Contact_HasActivityInCampaign extends CRM_Civirules_Condition {

  private $_query = NULL;
  private $_index = NULL;
  private $_queryParams = [];

  /**
   * Method to determine if condition is valid
   *
   * @param CRM_Civirules_TriggerData_TriggerData $triggerData
   * @return bool
   */
  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $return = FALSE;
    $contactId = $triggerData->getContactId();
    if ($contactId) {
      $this->_query = 'SELECT COUNT(*)
      FROM civicrm_activity AS act
      JOIN civicrm_activity_contact AS contact ON act.id = contact.activity_id AND contact.record_type_id = %1
      WHERE act.is_test = %2 AND contact.contact_id = %3';
      $this->_queryParams = [
        1 => [3, 'Integer'],
        2 => [0, 'Integer'],
        3 => [$contactId, 'Integer'],
      ];
      $this->_index = 3;
      // add activity type and campaign clause(s)
      $this->addWhereClauses('activity_type_id');
      $this->addWhereClauses('campaign_id');
      // only check if there are actually activity types and campaigns in the condition parameters
      if ($this->_index > 3) {
        $count = CRM_Core_DAO::singleValueQuery($this->_query, $this->_queryParams);
        if ($count > 0) {
          $return = TRUE;
        }
      }
    }
    return $return;
  }

  /**
   * Method to set the where clauses
   *
   * @param $fieldName
   */
  private function addWhereClauses($fieldName) {
    $fieldIds = [];
    foreach ($this->conditionParams[$fieldName] as $fieldValue) {
      $this->_index++;
      $fieldIds[] = '%' . $this->_index;
      $this->_queryParams[$this->_index] = [$fieldValue, 'Integer'];
    }
    if (!empty($fieldIds)) {
      $this->_query .= ' AND act.' . $fieldName . ' IN (' . implode(', ', $fieldIds) . ')';
    }
  }

  /**
   * Returns a redirect url to extra data input from the user after adding a condition
   *
   * Return false if you do not need extra data input
   *
   * @param int $ruleConditionId
   * @return bool|string
   * @access public
   * @abstract
   */
  public function getExtraDataInputUrl($ruleConditionId) {
    return $this->getFormattedExtraDataInputUrl('civicrm/civirule/form/condition/contact/hasactivityincampaign', $ruleConditionId);
  }

  /**
   * Returns a user friendly text explaining the condition params
   * e.g. 'Older than 65'
   *
   * @return string
   * @access public
   */
  public function userFriendlyConditionParams() {
    $activityTypeLabels = [];
    foreach ($this->conditionParams['activity_type_id'] as $activityTypeId) {
      try {
        $activityTypeLabels[] = civicrm_api3('OptionValue', 'getvalue', [
          'option_group_id' => 'activity_type',
          'value' => $activityTypeId,
          'return' => 'label',
        ]);
      }
      catch (CRM_Core_Exception $ex) {
      }
    }
    if (!empty($activityTypeLabels)) {
      $text = ts('has activities of type(s)') . ': ' . implode('; ', $activityTypeLabels);
    }
    else {
      $text = ts('has activities of type(s)') . ': ' . implode('; ', $this->conditionParams['activity_type_id']);

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
      $text .= ts(' in campaign(s)') . ': ' . implode('; ', $campaignTitles);
    }
    else {
      $text .= ts(' in campaign(s)') . ': ' . implode('; ', $this->conditionParams['campaign_id']);

    }
    return $text;
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
    if (!empty($params['activity_type_id'])) {
      try {
        $params['activity_type_id'] = civicrm_api3('OptionValue', 'getvalue', [
          'return' => 'name',
          'value' => $params['activity_type_id'],
          'option_group_id' => 'activity_type',
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

}
