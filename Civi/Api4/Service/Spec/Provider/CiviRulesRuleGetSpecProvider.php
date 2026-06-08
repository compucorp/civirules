<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Query\Api4SelectQuery;
use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * @service
 * @internal
 */
class CiviRulesRuleGetSpecProvider extends \Civi\Core\Service\AutoService implements Generic\SpecProviderInterface {

  /**
   * @param \Civi\Api4\Service\Spec\RequestSpec $spec
   *
   * @throws \CRM_Core_Exception
   */
  public function modifySpec(RequestSpec $spec): void {
    // Calculated field counts contacts in group
    $field = new FieldSpec('trigger_params_display', 'CiviRulesRule', 'String');
    $field->setLabel(ts('Trigger Params Description'))
      ->setTitle(ts('Trigger Params Description'))
      ->setColumnName('trigger_params')
      ->setDescription(ts('Human-readable description of trigger parameters'))
      ->setType('Extra')
      ->setReadonly(TRUE)
      ->addOutputFormatter([__CLASS__, 'description']);
    $spec->addFieldSpec($field);

    // Calculated field for last run/trigger date
    $lastRun = new FieldSpec('last_run_date', 'CiviRulesRule', 'Timestamp');
    $lastRun->setLabel(ts('Last Run Date'))
      ->setDescription(ts('When this rule was last triggered'))
      ->setColumnName('id')
      ->setType('Extra')
      ->setReadonly(TRUE)
      ->setSqlRenderer([__CLASS__, 'renderLastRunDate']);
    $spec->addFieldSpec($lastRun);

    // Virtual field for rule tags
    $tags = new FieldSpec('tag_id', 'CiviRulesRule', 'Array');
    $tags->setLabel(ts('Tags'))
      ->setTitle(ts('Tags'))
      ->setDescription(ts('Tags belonging to this rule.'))
      ->setType('Extra')
      ->setInputType('Select')
      ->setInputAttrs(['multiple' => TRUE])
      ->setSerialize(\CRM_Core_DAO::SERIALIZE_COMMA)
      ->setSuffixes(['id', 'name', 'label'])
      ->setOptionsCallback([__CLASS__, 'getTagOptions'])
      ->setColumnName('id')
      ->setSqlRenderer([__CLASS__, 'renderTags']);
    $spec->addFieldSpec($tags);
  }

  /**
   * @param string $entity
   * @param string $action
   *
   * @return bool
   */
  public function applies($entity, $action): bool {
    return $entity === 'CiviRulesRule';
  }

  public static function description(&$value, $row) {
    $triggerClass = \CRM_Civirules_BAO_Trigger::getTriggerObjectByTriggerId($row['trigger_id'], FALSE);
    if ($triggerClass) {
      $triggerClass->setTriggerId($row['trigger_id']);
      $triggerClass->setTriggerParams($row['trigger_params_display']);
      $value = $triggerClass->getTriggerDescription();
    }
  }

  public static function renderLastRunDate(array $field): string {
    return '(SELECT MAX(log_date) FROM civirule_rule_log WHERE rule_id = ' . $field['sql_name'] . ')';
  }

  public static function renderTags(array $field, Api4SelectQuery $query): string {
    return '(SELECT GROUP_CONCAT(rule_tag_id) FROM civirule_rule_tag WHERE rule_id = ' . $field['sql_name'] . ')';
  }

  public static function getTagOptions($field, $values, $returnFormat, $checkPermissions): array {
    return \Civi::entity('CiviRulesRuleTag')->getOptions('rule_tag_id', $values, FALSE, $checkPermissions);
  }

}
