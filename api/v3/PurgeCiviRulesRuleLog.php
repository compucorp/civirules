<?php

/**
 * Purge CiviRules rule log API.
 *
 * @param array<string,mixed> $params
 *
 * @return array
 *
 * @throws CRM_Core_Exception
 */
function civicrm_api3_purge_civi_rules_rule_log_run(array $params) {
  $purgeRuleLog = new CRM_Civirules_Job_PurgeRuleLog();
  $result = $purgeRuleLog->run($params);

  if ($result) {
    return civicrm_api3_create_success($result, $params);
  }

  return civicrm_api3_create_error('Error occurred while purging CiviRules rule log.', $params);
}

/**
 * Purge CiviRules rule log API specification.
 *
 * @param array<string,array<string,mixed>> $spec
 *   Description of fields supported by this API call.
 */
function _civicrm_api3_purge_civi_rules_rule_log_run_spec(array &$spec) {
  $spec['retention_days'] = [
    'title' => 'Retention Days',
    'description' => 'Number of days of rule log data to keep. Records older than this are deleted.',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 0,
    'api.default' => CRM_Civirules_Job_PurgeRuleLog::DEFAULT_RETENTION_DAYS,
  ];
  $spec['max_rows'] = [
    'title' => 'Maximum Rows Per Run',
    'description' => 'Ceiling on the number of rows removed in one run, so a large backlog is cleared over several runs instead of one long one. Use 0 for no ceiling.',
    'type' => CRM_Utils_Type::T_INT,
    'api.required' => 0,
    'api.default' => CRM_Civirules_Job_PurgeRuleLog::DEFAULT_MAX_ROWS,
  ];
}
