<?php

/**
 * CiviRulesRuleLog.Purge API specification.
 *
 * @param array $spec description of fields supported by this API call
 */
function _civicrm_api3_civi_rules_rule_log_purge_spec(&$spec) {
  $spec['retention_days'] = [
    'title' => 'Retention Days',
    'description' => 'Number of days of rule log data to keep. Records older than this are deleted. Must be a whole number of 1 or more.',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 0,
    'api.default' => CRM_Civirules_Job_PurgeRuleLog::DEFAULT_RETENTION_DAYS,
  ];
  $spec['max_rows'] = [
    'title' => 'Maximum Rows Per Run',
    'description' => 'Ceiling on the number of rows removed in one run, so a large backlog is cleared over several runs instead of one long one. Must be a whole number; 0 means no ceiling. Note that 0 does not mean the same thing here as it would for retention_days, which requires at least 1.',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 0,
    'api.default' => CRM_Civirules_Job_PurgeRuleLog::DEFAULT_MAX_ROWS,
  ];
}

/**
 * CiviRulesRuleLog.Purge API
 *
 * Deletes rule log records older than the retention period.
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws \CRM_Core_Exception
 */
function civicrm_api3_civi_rules_rule_log_purge($params) {
  $purgeRuleLog = new CRM_Civirules_Job_PurgeRuleLog();
  $result = $purgeRuleLog->run($params);

  if ($result === NULL) {
    return civicrm_api3_create_error('Error occurred while purging CiviRules rule log. See the CiviCRM log for details.', $params);
  }

  $dao = NULL;

  return civicrm_api3_create_success($result['message'], $params, 'CiviRulesRuleLog', 'purge', $dao, [
    'deleted' => $result['deleted'],
    'retention_days' => $result['retention_days'],
    'max_rows' => $result['max_rows'],
    'ceiling_reached' => $result['ceiling_reached'],
  ]);
}
