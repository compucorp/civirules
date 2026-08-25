<?php

namespace Civi\Civirules;

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Action/condition weights are ordered within a rule, never across rules.
 *
 * @group headless
 */
class RuleItemWeightTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $ruleA;

  private int $ruleB;

  private int $actionTypeId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $triggerId = CiviRulesTrigger::get(FALSE)->addSelect('id')->setLimit(1)->execute()->first()['id'];
    $this->ruleA = $this->createRule($triggerId, 'phpunit_weight_a');
    $this->ruleB = $this->createRule($triggerId, 'phpunit_weight_b');
    $this->actionTypeId = CiviRulesAction::get(FALSE)->addSelect('id')
      ->addWhere('name', '=', 'contact_tag_add')->execute()->first()['id'];
  }

  private function createRule(int $triggerId, string $name): int {
    return CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => $name,
        'name' => $name,
        'trigger_id' => $triggerId,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
  }

  private function addAction(int $ruleId, ?int $weight = NULL): int {
    $create = CiviRulesRuleAction::create(FALSE)
      ->addValue('rule_id', $ruleId)
      ->addValue('action_id', $this->actionTypeId)
      ->addValue('action_params', ['tag_id' => [1]]);
    if ($weight !== NULL) {
      $create->addValue('weight', $weight);
    }
    return $create->execute()->first()['id'];
  }

  /**
   * @return array id => weight, ordered by id
   */
  private function weightsOf(int $ruleId): array {
    return (array) CiviRulesRuleAction::get(FALSE)
      ->addSelect('id', 'weight')
      ->addWhere('rule_id', '=', $ruleId)
      ->addOrderBy('id', 'ASC')
      ->execute()
      ->indexBy('id')
      ->column('weight');
  }

  public function testEachRuleNumbersItsActionsFromOne(): void {
    $a1 = $this->addAction($this->ruleA);
    $a2 = $this->addAction($this->ruleA);
    $b1 = $this->addAction($this->ruleB);
    $b2 = $this->addAction($this->ruleB);

    $this->assertSame([$a1 => 1, $a2 => 2], $this->weightsOf($this->ruleA));
    $this->assertSame([$b1 => 1, $b2 => 2], $this->weightsOf($this->ruleB));
  }

  /**
   * Duplicate-weight correction used to run over the whole table, so adding an
   * action to one rule renumbered every other rule's actions.
   */
  public function testInsertingIntoOneRuleLeavesOtherRulesAlone(): void {
    $this->addAction($this->ruleA);
    $this->addAction($this->ruleA);
    $this->addAction($this->ruleB);
    $this->addAction($this->ruleB);
    $before = $this->weightsOf($this->ruleB);

    // Insert at the top of rule A, i.e. at a weight rule A already uses.
    $this->addAction($this->ruleA, 1);

    $this->assertSame($before, $this->weightsOf($this->ruleB));
  }

  public function testInsertingAtAnOccupiedWeightStillOrdersTheRuleCorrectly(): void {
    $first = $this->addAction($this->ruleA);
    $second = $this->addAction($this->ruleA);

    $inserted = $this->addAction($this->ruleA, 1);

    $order = CiviRulesRuleAction::get(FALSE)
      ->addSelect('id')
      ->addWhere('rule_id', '=', $this->ruleA)
      ->addOrderBy('weight', 'ASC')
      ->execute()->column('id');
    $this->assertSame([$inserted, $first, $second], $order);
    $weights = array_values($this->weightsOf($this->ruleA));
    sort($weights);
    $this->assertSame([1, 2, 3], $weights, 'Weights should be consecutive with no duplicates');
  }

  /**
   * Existing installs have weights allocated across the whole table.
   */
  public function testUpgradeRenumbersWeightsPerRulePreservingOrder(): void {
    $a1 = $this->addAction($this->ruleA);
    $a2 = $this->addAction($this->ruleA);
    $b1 = $this->addAction($this->ruleB);
    $b2 = $this->addAction($this->ruleB);

    // Simulate the old global numbering.
    foreach ([$a1 => 10, $a2 => 20, $b1 => 30, $b2 => 40] as $id => $weight) {
      \CRM_Core_DAO::executeQuery('UPDATE civirule_rule_action SET weight = %1 WHERE id = %2', [
        1 => [$weight, 'Integer'],
        2 => [$id, 'Integer'],
      ]);
    }

    $ctx = new \CRM_Queue_TaskContext();
    $ctx->log = \Civi::log();
    \CRM_Civirules_Upgrader::_queueAdapter($ctx, 'org.civicoop.civirules', 'upgrade_3010');

    $this->assertSame([$a1 => 1, $a2 => 2], $this->weightsOf($this->ruleA));
    $this->assertSame([$b1 => 1, $b2 => 2], $this->weightsOf($this->ruleB));
  }

}
