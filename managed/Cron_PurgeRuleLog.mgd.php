<?php

return [
  0 =>
    [
      'name' => 'Cron:CiviRulesRuleLog.Purge',
      'entity' => 'Job',
      'update' => 'never',
      'params' =>
        [
          'version' => 3,
          'name' => 'Purge CiviRules rule log',
          'description' => 'Deletes CiviRules rule log records older than the configured number of retention days.',
          'run_frequency' => 'Weekly',
          'api_entity' => 'PurgeCiviRulesRuleLog',
          'api_action' => 'run',
          'parameters' => "retention_days=90\nmax_rows=500000",
          'is_active' => '1',
        ],
    ],
];
