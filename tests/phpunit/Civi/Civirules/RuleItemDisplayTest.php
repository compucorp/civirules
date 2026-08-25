<?php

namespace Civi\Civirules;

use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * The rule edit screen lists conditions/actions through APIv4 calculated fields
 * (condition_params_display / action_params_display) which instantiate the
 * condition/action class for every row. A row whose class is unavailable - e.g.
 * the extension that supplied it has been uninstalled - must degrade to a single
 * unreadable cell rather than aborting the whole result set.
 *
 * @group headless
 */
class RuleItemDisplayTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $ruleId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $triggerId = CiviRulesTrigger::get(FALSE)
      ->addSelect('id')
      ->setLimit(1)
      ->execute()
      ->first()['id'];
    $this->ruleId = CiviRulesRule::create(FALSE)
      ->addValue('label', 'RuleItemDisplayTest')
      ->addValue('name', 'ruleitemdisplaytest')
      ->addValue('trigger_id', $triggerId)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first()['id'];
  }

  private function getConditionIdByClass(string $className): int {
    return CiviRulesCondition::get(FALSE)
      ->addSelect('id')
      ->addWhere('class_name', '=', $className)
      ->execute()
      ->first()['id'];
  }

  /**
   * Register a civirule_condition whose class_name does not resolve, and attach it
   * to the rule. Written with raw SQL because the APIv4 create would be a fine place
   * for CiviRules to start rejecting unknown classes, and this fixture must keep
   * reproducing the stored-data state regardless.
   */
  private function addConditionWithMissingClass(): int {
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_condition (name, label, class_name, is_active) VALUES ('phpunit_missing', 'PHPUnit Missing', 'CRM_Nonexistent_PhpunitClass', 1)"
    );
    $conditionId = (int) \CRM_Core_DAO::singleValueQuery("SELECT id FROM civirule_condition WHERE name = 'phpunit_missing'");
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_condition (rule_id, condition_id, condition_link, is_active, weight) VALUES (%1, %2, %3, 1, 99)',
      [
        1 => [$this->ruleId, 'Integer'],
        2 => [$conditionId, 'Integer'],
        3 => ['AND', 'String'],
      ]
    );
    return $conditionId;
  }

  private function getConditionRows(): array {
    return (array) CiviRulesRuleCondition::get(FALSE)
      ->addSelect('id', 'condition_id', 'condition_params_display')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->addOrderBy('weight', 'ASC')
      ->execute()
      ->getArrayCopy();
  }

  public function testUnavailableConditionClassDoesNotAbortTheWholeResultSet(): void {
    $goodConditionId = CiviRulesRuleCondition::create(FALSE)
      ->addValue('rule_id', $this->ruleId)
      ->addValue('condition_id', $this->getConditionIdByClass('CRM_CivirulesConditions_Contact_HasType'))
      ->addValue('condition_params', ['operator' => 'in', 'type_names' => ['Individual']])
      ->execute()
      ->first()['id'];
    $this->addConditionWithMissingClass();

    $rows = $this->getConditionRows();

    // Before hardening this returned zero rows: the stale row threw
    // 'CiviRule condition class "..." does not exist' out of the whole api call.
    $this->assertCount(2, $rows);
    $this->assertSame($goodConditionId, $rows[0]['id']);
    $this->assertStringContainsString('Individual', $rows[0]['condition_params_display']);
    $this->assertStringContainsString('not found', $rows[1]['condition_params_display']);
  }

  public function testUnavailableActionClassDoesNotAbortTheWholeResultSet(): void {
    \CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_action (name, label, class_name, is_active) VALUES ('phpunit_missing_action', 'PHPUnit Missing Action', 'CRM_Nonexistent_PhpunitActionClass', 1)"
    );
    $actionId = (int) \CRM_Core_DAO::singleValueQuery("SELECT id FROM civirule_action WHERE name = 'phpunit_missing_action'");
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_action (rule_id, action_id, is_active, weight) VALUES (%1, %2, 1, 99)',
      [1 => [$this->ruleId, 'Integer'], 2 => [$actionId, 'Integer']]
    );

    $rows = (array) CiviRulesRuleAction::get(FALSE)
      ->addSelect('id', 'action_id', 'action_params_display')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->execute()
      ->getArrayCopy();

    $this->assertCount(1, $rows);
    $this->assertStringContainsString('not found', $rows[0]['action_params_display']);
  }

  /**
   * getLinks resolves the edit link by instantiating the class, inside a
   * civi.api.respond listener - an uncaught error there takes down the caller.
   */
  public function testGetLinksSurvivesAnUnavailableConditionClass(): void {
    $conditionId = $this->addConditionWithMissingClass();
    $ruleConditionId = (int) \CRM_Core_DAO::singleValueQuery(
      'SELECT id FROM civirule_rule_condition WHERE rule_id = %1',
      [1 => [$this->ruleId, 'Integer']]
    );

    $links = CiviRulesRuleCondition::getLinks(FALSE)
      ->addWhere('ui_action', '=', 'update')
      ->addValue('id', $ruleConditionId)
      ->addValue('rule_id', $this->ruleId)
      ->addValue('condition_id', $conditionId)
      ->execute();

    // The link is dropped rather than emitted with a FALSE path.
    foreach ($links as $link) {
      $this->assertNotFalse($link['path'] ?? NULL);
    }
    $this->assertTrue(TRUE);
  }

}
