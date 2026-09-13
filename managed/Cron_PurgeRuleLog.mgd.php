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
          'description' => 'Deletes CiviRules rule log records older than the configured number of retention days. Warning: some cron triggers (Activity Date, Activity Scheduled Date, Event Date, No Case Activity Since) use the rule log to record that a rule has already fired for an entity, with no date limit. Purging those rows can cause affected rules to fire again for historical records. Check which triggers your rules use before enabling this job.',
          'run_frequency' => 'Weekly',
          'api_entity' => 'CiviRulesRuleLog',
          'api_action' => 'purge',
          'parameters' => "retention_days=90\nmax_rows=500000",
          'is_active' => '0',
        ],
    ],
];
