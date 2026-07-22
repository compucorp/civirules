<?php

use CRM_Civirules_ExtensionUtil as E;

/**
 * CiviRulesRule.Create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 */
function _civicrm_api3_civi_rules_rule_create_spec(&$spec) {
  $spec['id']['api_required'] = 0;
  $spec['label']['api_required'] = 0;
  $spec['name']['api_required'] = 0;
  $spec['trigger_id']['api_required'] = 1;
}

/**
 * CiviRulesRule.Create API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 */
function civicrm_api3_civi_rules_rule_create($params) {
  if (!isset($params['id']) && empty($params['label'])) {
    return civicrm_api3_create_error('Label can not be empty when adding a new CiviRules');
  }
  if (!isset($params['id']) && empty($params['trigger_id'])) {
    return civicrm_api3_create_error('Trigger_id can not be empty');
  }

  // set created or modified date and user_id
  $session = CRM_Core_Session::singleton();
  $userId = $session->get('userID');
  if (isset($params['id'])) {
    $params['modified_date'] = date('Ymd');
    $params['modified_user_id'] = $userId;
  }
  else {
    $params['created_date'] = date('Ymd');
    $params['created_user_id'] = $userId;
  }
  $returnValues = CRM_Civirules_BAO_CiviRulesRule::writeRecord($params);
  $keyedReturnValues = [$returnValues->id => $returnValues->toArray()];
  return civicrm_api3_create_success($keyedReturnValues, $params, 'CiviRulesRule', 'Create');
}

/**
 * CiviRulesRule.Get API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \CRM_Core_Exception
 * @see civicrm_api3_create_error
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civi_rules_rule_get($params) {
  $returnValues = CRM_Civirules_BAO_CiviRulesRule::getValues($params);
  return civicrm_api3_create_success($returnValues, $params, 'CiviRulesRule', 'Get');
}

/**
 * CiviRulesRule.Delete API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 */
function _civicrm_api3_civi_rules_rule_delete_spec(&$spec) {
  $spec['id']['api_required'] = 1;
}

/**
 * CiviRulesRule.Delete API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \CRM_Core_Exception
 * @see civicrm_api3_create_error
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civi_rules_rule_delete($params) {
  if (!array_key_exists('id', $params) || empty($params['id'])) {
    throw new CRM_Core_Exception('Parameter id is mandatory and can not be empty in ' . __METHOD__, 0010);
  }
  else {
    CRM_Civirules_BAO_CiviRulesRule::deleteWithId($params['id']);
    return civicrm_api3_create_success(1, $params, 'CiviRulesRule', 'Delete');
  }
}

/**
 * CiviRulesRule.Clone API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 */
function _civicrm_api3_civi_rules_rule_clone_spec(&$spec) {
  $spec['id']['api.required'] = 1;
  $spec['id']['type'] = CRM_Utils_Type::T_INT;
  $spec['id']['title'] = 'Unique ID of the rule to be cloned';
}

/**
 * CiviRulesRule.Clone API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \CRM_Core_Exception
 * @see civicrm_api3_create_error
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civi_rules_rule_clone($params) {
  $ruleID = $params['id'];
  $cloneId = CRM_Civirules_BAO_CiviRulesRule::cloneRule($ruleID);
  $resultValues = [
    'id' => $ruleID,
    'clone_id' => $cloneId,
  ];
  return civicrm_api3_create_success($resultValues, $params, 'CiviRulesRule', 'clone');
}

/**
 * CiviRulesRule.GetClones API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @return void
 */
function _civicrm_api3_civi_rules_rule_getclones_spec(&$spec) {
  $spec['id']['api.required'] = 1;
  $spec['id']['type'] = CRM_Utils_Type::T_INT;
  $spec['id']['title'] = 'Unique ID  of a rule';
}

/**
 * CiviRulesRule.GetClones API
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @throws \CRM_Core_Exception
 * @see civicrm_api3_create_error
 * @see civicrm_api3_create_success
 */
function civicrm_api3_civi_rules_rule_getclones($params) {
  $Id = $params['id'];
  $rule = civicrm_api3('CiviRulesRule', 'getsingle', ['id' => $Id]);
  $clones = [];
  if (!$rule['is_active']) {
    $triggerId = $rule['trigger_id'];
    $ruleFormat = CRM_Civirules_Utils::ruleCompareFormat($Id, $triggerId);
    $sql = "select id,label from civirule_rule where trigger_id = %1 and id != %2";
    $dao = CRM_Core_DAO::executeQuery($sql, [
      1 => [$triggerId, 'Integer'],
      2 => [$Id, 'Integer'],
    ]);

    while ($dao->fetch()) {
      $cloneFormat = CRM_Civirules_Utils::ruleCompareFormat($dao->id, $triggerId);
      if ($cloneFormat == $ruleFormat) {
        $clones[$dao->id] = ['id' => $dao->id, 'label' => $dao->label];
      }
    }
  }
  return civicrm_api3_create_success($clones, $params, 'CiviRulesRule', 'getClones');
}
