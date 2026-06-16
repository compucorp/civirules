<?php

/**
 * Managed entities for the Portal Access multi-record custom field group.
 */

use CRM_Civirules_ExtensionUtil as E;

return [
  [
    'name'    => 'CustomGroup_Portal_Access',
    'entity'  => 'CustomGroup',
    'cleanup' => 'unused',
    'update'  => 'unmodified',
    'params'  => [
      'version' => 4,
      'values'  => [
        'name'                 => 'Portal_Access',
        'title'                => E::ts('Portal Access'),
        'extends'              => 'Contact',
        'style'                => 'Tab with table',
        'is_multiple'          => TRUE,
        'collapse_adv_display' => FALSE,
        'is_active'            => TRUE,
        'weight'               => 10,
      ],
      'match' => ['name'],
    ],
  ],
  [
    'name'    => 'CustomField_Portal_Access_portal_type',
    'entity'  => 'CustomField',
    'cleanup' => 'unused',
    'update'  => 'unmodified',
    'params'  => [
      'version' => 4,
      'values'  => [
        'custom_group_id.name' => 'Portal_Access',
        'name'                 => 'portal_type',
        'label'                => E::ts('Type'),
        'data_type'            => 'String',
        'html_type'            => 'Text',
        'text_length'          => 64,
        'is_active'            => TRUE,
        'is_required'          => FALSE,
        'is_searchable'        => TRUE,
        'weight'               => 1,
      ],
      'match' => ['custom_group_id', 'name'],
    ],
  ],
  [
    'name'    => 'CustomField_Portal_Access_portal_timestamp',
    'entity'  => 'CustomField',
    'cleanup' => 'unused',
    'update'  => 'unmodified',
    'params'  => [
      'version' => 4,
      'values'  => [
        'custom_group_id.name' => 'Portal_Access',
        'name'                 => 'portal_timestamp',
        'label'                => E::ts('Timestamp'),
        'data_type'            => 'Date',
        'html_type'            => 'Select Date',
        'date_format'          => 'yy-mm-dd',
        'time_format'          => 2,
        'is_active'            => TRUE,
        'is_required'          => FALSE,
        'is_searchable'        => TRUE,
        'weight'               => 2,
      ],
      'match' => ['custom_group_id', 'name'],
    ],
  ],
  [
    'name'    => 'CustomField_Portal_Access_portal_url',
    'entity'  => 'CustomField',
    'cleanup' => 'unused',
    'update'  => 'unmodified',
    'params'  => [
      'version' => 4,
      'values'  => [
        'custom_group_id.name' => 'Portal_Access',
        'name'                 => 'portal_url',
        'label'                => E::ts('URL'),
        'data_type'            => 'String',
        'html_type'            => 'Text',
        'text_length'          => 512,
        'is_active'            => TRUE,
        'is_required'          => FALSE,
        'is_searchable'        => TRUE,
        'weight'               => 3,
      ],
      'match' => ['custom_group_id', 'name'],
    ],
  ],

];
