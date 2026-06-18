<?php

class CRM_CivirulesConditions_Contact_HasType extends CRM_Civirules_Condition {

  /**
   * This method returns true or false when an condition is valid or not
   *
   * @param CRM_Civirules_TriggerData_TriggerData $triggerData
   * @return bool
   * @access public
   */
  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $isConditionValid = FALSE;
    $contact = $triggerData->getEntityData('Contact');

    switch ($this->conditionParams['operator']) {
      case 'is one of':
        $isConditionValid = in_array($contact['contact_type'], $this->conditionParams['type_names']);
        break;

      case 'is not one of':
        $isConditionValid = !in_array($contact['contact_type'], $this->conditionParams['type_names']);;
        break;
    }
    return $isConditionValid;
  }

  /**
   * Returns a redirect url to extra data input from the user after adding a condition
   *
   * Return false if you do not need extra data input
   *
   * @param int $ruleConditionId
   * @return bool|string
   * @access public
   */
  public function getExtraDataInputUrl($ruleConditionId) {
    return $this->getFormattedExtraDataInputUrl('civicrm/civirule/form/condition/contact_hastype', $ruleConditionId);
  }

  /**
   * Returns a user friendly text explaining the condition params
   * e.g. 'Older than 65'
   *
   * @return string
   * @access public
   */
  public function userFriendlyConditionParams() {

    $contact_types = CRM_Civirules_Utils::getContactTypes();
    $operators = $this->getOperatorOptions();
    $operator = $this->conditionParams['operator'];
    $operatorLabel = ts('unknown');
    if (isset($operators[$operator])) {
      $operatorLabel = $operators[$operator];
    }

    $types = '';
    foreach ($this->conditionParams['type_names'] as $type) {
      if (strlen($types)) {
        $types .= ', ';
      }
      $types .= $contact_types[$type];
    }
    return $operatorLabel . ': ' . $types;
  }

  /**
   * Method to get operators
   *
   * @return array
   * @access public
   * @static
   */
  public static function getOperatorOptions() {
    return [
      'is one of' => ts('is one of'),
      'is not one of' => ts('is not one of'),
    ];
  }

}
