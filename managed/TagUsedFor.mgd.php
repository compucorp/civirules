<?php
use CRM_Civirules_ExtensionUtil as E;

// Adds option value to `tag_used_for`, allowing CiviRules to be tagged
return [
  [
    'name' => 'CiviRulesRule:tag_used_for',
    'entity' => 'OptionValue',
    'cleanup' => 'always',
    'update' => 'always',
    'params' => [
      'version' => 4,
      'values' => [
        'option_group_id.name' => 'tag_used_for',
        'value' => 'civirule_rule',
        'name' => 'CiviRulesRule',
        'label' => E::ts('CiviRules'),
        'is_reserved' => TRUE,
        'is_active' => TRUE,
        'domain_id' => NULL,
      ],
      'match' => ['option_group_id', 'name'],
    ],
  ],
];
