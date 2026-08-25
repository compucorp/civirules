<?php

namespace Civi\Civirules;

use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\SearchDisplay;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * How an unconfigured rule condition appears on the rule edit screen.
 *
 * @group headless
 */
class UnconfiguredDisplayTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $ruleId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    \CRM_Civirules_Utils_ConfigurationStatus::flushCache();
    // SearchDisplay::run resolves links via a permission-checked getFields call,
    // regardless of the run itself being unchecked.
    \CRM_Core_Config::singleton()->userPermissionClass->permissions = [
      'access CiviCRM',
      'administer CiviCRM',
      'administer CiviRules',
    ];

    $this->ruleId = CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'PHPUnit Display Rule',
        'name' => 'phpunit_display_rule',
        'trigger_id' => CiviRulesTrigger::get(FALSE)->addSelect('id')->setLimit(1)->execute()->first()['id'],
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
  }

  private function addCondition(?array $params, int $weight): int {
    $values = [
      'rule_id' => $this->ruleId,
      'condition_id' => CiviRulesCondition::get(FALSE)->addWhere('name', '=', 'contact_in_group')->execute()->first()['id'],
      'is_active' => TRUE,
      'weight' => $weight,
    ];
    if ($params !== NULL) {
      $values['condition_params'] = $params;
    }
    return CiviRulesRuleCondition::create(FALSE)->setValues($values)->execute()->first()['id'];
  }

  private function runDisplay(): array {
    return (array) SearchDisplay::run(FALSE)
      ->setSavedSearch('CiviRules_Rule_Conditions')
      ->setDisplay('CiviRules_Rule_Conditions')
      ->setFilters(['rule_id' => $this->ruleId])
      ->setSort([['weight', 'ASC']])
      ->execute()
      ->getArrayCopy();
  }

  /**
   * Without a marker the "Details" column describes the missing parameters as
   * though they had been set - an unconfigured "contact in group" reads as
   * "unknown groups (), with statuses (Added)", which looks configured.
   */
  public function testUnconfiguredConditionIsLabelledNotConfigured(): void {
    $this->addCondition(NULL, 1);
    $this->addCondition(['operator' => 'in', 'group_ids' => [1]], 2);

    $rows = $this->runDisplay();

    $this->assertCount(2, $rows);
    $this->assertStringContainsString('Not configured', (string) $this->detailColumn($rows[0]));
    $this->assertStringNotContainsString('Not configured', (string) $this->detailColumn($rows[1]));
  }

  public function testUnconfiguredConditionRowIsHighlighted(): void {
    $this->addCondition(NULL, 1);
    $this->addCondition(['operator' => 'in', 'group_ids' => [1]], 2);

    $rows = $this->runDisplay();

    $this->assertStringContainsString('bg-warning', $rows[0]['cssClass'] ?? '');
    $this->assertStringNotContainsString('bg-warning', $rows[1]['cssClass'] ?? '');
  }

  /**
   * The rendered columns carry no key, so address the "Details"
   * (condition_params_display) column by its position in the display definition.
   *
   * @param array $row
   *
   * @return mixed
   */
  private function detailColumn(array $row) {
    return $row['columns'][2]['val'] ?? NULL;
  }

}
