<?php

namespace Civi\Civirules;

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\FormTrait;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * The type picker hands over to the configuration form instead of writing a row.
 *
 * @group headless
 */
class SingleStepAddTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  use FormTrait;

  private int $ruleId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    unset($_GET['rule_action_id'], $_POST['rule_action_id'], $_REQUEST['rule_action_id']);
    unset($_GET['rule_condition_id'], $_POST['rule_condition_id'], $_REQUEST['rule_condition_id']);
    $this->ruleId = CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'phpunit_single_step',
        'name' => 'phpunit_single_step',
        'trigger_id' => CiviRulesTrigger::get(FALSE)->addWhere('name', '=', 'new_activity')->execute()->first()['id'],
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
  }

  private function actionTypeId(string $name): int {
    return CiviRulesAction::get(FALSE)->addSelect('id')->addWhere('name', '=', $name)->execute()->first()['id'];
  }

  private function conditionTypeId(string $name): int {
    return CiviRulesCondition::get(FALSE)->addSelect('id')->addWhere('name', '=', $name)->execute()->first()['id'];
  }

  /**
   * The action picker's own form cannot be driven here: it bounces unless _action is
   * ADD, and FormWrapper builds a plain CRM_Core_Controller which never sets it from
   * the url. The equivalent condition test below does cover the handover end to end,
   * and getAddConfigUrl() - the decision the picker makes - is asserted directly.
   */
  public function testPickingAConfigurableConditionWritesNothing(): void {
    $this->getTestForm('CRM_Civirules_Form_RuleCondition',
      [
        'rule_id' => $this->ruleId,
        'rid' => $this->ruleId,
        'rule_condition_select' => $this->conditionTypeId('contact_has_type'),
      ],
      ['rid' => $this->ruleId, 'action' => 'add']
    )->processForm();

    $this->assertSame(0, CiviRulesRuleCondition::get(FALSE)
      ->addWhere('rule_id', '=', $this->ruleId)->execute()->count());
  }

  /**
   * Every option in the picker must lead somewhere, whether or not the type has a
   * configuration form of its own.
   */
  public function testEveryPickerOptionHasATarget(): void {
    $trigger = \CRM_Civirules_BAO_CiviRulesTrigger::getPostTriggerObjectByClassName('CRM_CivirulesPostTrigger_Activity', TRUE);
    $rule = new \CRM_Civirules_BAO_CiviRulesRule();
    $rule->id = $this->ruleId;
    $rule->find(TRUE);

    $actionOptions = \CRM_Civirules_Form_RuleAction::getActionOptions($trigger, $rule);
    $this->assertNotEmpty($actionOptions);
    $actionUrls = \CRM_Civirules_Form_RuleAction::getTypeUrlMap($actionOptions, $this->ruleId);
    $this->assertCount(count($actionOptions), $actionUrls);
    foreach ($actionUrls as $id => $url) {
      $this->assertNotEmpty($url, "Action {$id} has no target url");
    }

    $conditionOptions = \CRM_Civirules_Form_RuleCondition::getConditionOptions($trigger, $rule);
    $this->assertNotEmpty($conditionOptions);
    $conditionUrls = \CRM_Civirules_Form_RuleCondition::getTypeUrlMap($conditionOptions, $this->ruleId);
    $this->assertCount(count($conditionOptions), $conditionUrls);
    foreach ($conditionUrls as $id => $url) {
      $this->assertNotEmpty($url, "Condition {$id} has no target url");
    }
  }

  /**
   * The configuration form url must carry everything needed to create the row, since
   * there is no row yet to look any of it up from.
   */
  public function testConfigUrlCarriesTheTypeAndRule(): void {
    $actionId = $this->actionTypeId('contact_tag_add');
    $url = \CRM_Civirules_Form_RuleAction::getAddConfigUrl($actionId, $this->ruleId);

    $this->assertStringContainsString('rule_id=' . $this->ruleId, $url);
    $this->assertStringContainsString('action_id=' . $actionId, $url);
    // ContactTagAdd's form needs to know which entity's tags to offer.
    $this->assertStringContainsString('tn=civicrm_contact', $url);
  }

  public function testConfigUrlIsNullForATypeNeedingNoConfiguration(): void {
    $this->assertNull(
      \CRM_Civirules_Form_RuleAction::getAddConfigUrl($this->actionTypeId('log_to_civicrm'), $this->ruleId)
    );
  }

  /**
   * The link operator is chosen in the picker but the row is created later.
   */
  public function testConditionLinkSurvivesTheHandover(): void {
    $conditionId = $this->conditionTypeId('contact_has_type');
    $url = \CRM_Civirules_Form_RuleCondition::getAddConfigUrl($conditionId, $this->ruleId, 'OR');
    $this->assertStringContainsString('condition_link=OR', $url);

    $this->getTestForm('CRM_CivirulesConditions_Form_Contact_HasType',
      ['operator' => 'is one of', 'type_names' => ['Individual']],
      ['rule_id' => $this->ruleId, 'condition_id' => $conditionId, 'condition_link' => 'OR']
    )->processForm();

    $row = CiviRulesRuleCondition::get(FALSE)
      ->addSelect('condition_link')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->execute()->first();
    // The first condition of a rule is normalised to no operator regardless.
    $this->assertNull($row['condition_link']);
  }

}
