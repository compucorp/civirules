<?php

namespace Civi\Civirules;

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Tag;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\FormTrait;
use Civi\Test\FormWrapper;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Configuration forms can create the rule action/condition themselves, so nothing
 * is written until the configuration is actually saved.
 *
 * @group headless
 */
class ConfigFormAddModeTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  use FormTrait;

  private int $ruleId;

  private int $tagId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    // FormWrapper merges url parameters into $_GET, which TransactionalInterface does
    // not roll back - a stale rule_action_id would put the next test into edit mode
    // pointing at a row that no longer exists.
    unset($_GET['rule_action_id'], $_POST['rule_action_id'], $_REQUEST['rule_action_id']);

    $this->ruleId = CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'phpunit_add_mode',
        'name' => 'phpunit_add_mode',
        'trigger_id' => CiviRulesTrigger::get(FALSE)->addSelect('id')->setLimit(1)->execute()->first()['id'],
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
    $this->tagId = Tag::create(FALSE)
      ->addValue('name', 'PHPUnit Add Mode Tag')
      ->addValue('used_for', ['civicrm_contact'])
      ->execute()->first()['id'];
  }

  private function actionTypeId(string $name): int {
    return CiviRulesAction::get(FALSE)->addSelect('id')->addWhere('name', '=', $name)->execute()->first()['id'];
  }

  private function countActions(): int {
    return CiviRulesRuleAction::get(FALSE)->addWhere('rule_id', '=', $this->ruleId)->execute()->count();
  }

  public function testSavingTheConfigurationCreatesTheAction(): void {
    $this->assertSame(0, $this->countActions());

    $this->getTestForm('CRM_CivirulesActions_Tag_Form_EntityTag',
      ['tag_id' => [$this->tagId]],
      [
        'rule_id' => $this->ruleId,
        'action_id' => $this->actionTypeId('contact_tag_add'),
        'tn' => 'civicrm_contact',
      ]
    )->processForm();

    $this->assertSame(1, $this->countActions());
    $row = CiviRulesRuleAction::get(FALSE)
      ->addSelect('action_params', 'is_active', 'weight')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->execute()->first();
    // Read back through APIv4 so the DAO-direct pre-serialized write is checked
    // against the field's SERIALIZE_PHP handling.
    $this->assertEquals(['tag_id' => [$this->tagId]], $row['action_params']);
    $this->assertTrue((bool) $row['is_active']);
    $this->assertSame(1, $row['weight']);
  }

  /**
   * The whole point: abandoning the form must leave nothing behind.
   */
  public function testFailedValidationCreatesNothing(): void {
    $form = $this->getTestForm('CRM_CivirulesActions_Tag_Form_EntityTag',
      // tag_id is required.
      ['tag_id' => []],
      [
        'rule_id' => $this->ruleId,
        'action_id' => $this->actionTypeId('contact_tag_add'),
        'tn' => 'civicrm_contact',
      ]
    );
    $form->processForm(FormWrapper::VALIDATED);

    $this->assertSame(0, $this->countActions());
  }

  public function testEditingAnExistingActionDoesNotCreateAnother(): void {
    $existing = CiviRulesRuleAction::create(FALSE)
      ->setValues([
        'rule_id' => $this->ruleId,
        'action_id' => $this->actionTypeId('contact_tag_add'),
        'action_params' => ['tag_id' => [$this->tagId]],
        'is_active' => TRUE,
      ])
      ->execute()->first();

    $otherTag = Tag::create(FALSE)
      ->addValue('name', 'PHPUnit Other Tag')
      ->addValue('used_for', ['civicrm_contact'])
      ->execute()->first()['id'];

    $this->getTestForm('CRM_CivirulesActions_Tag_Form_EntityTag',
      ['tag_id' => [$otherTag], 'rule_action_id' => $existing['id']],
      ['rule_action_id' => $existing['id'], 'tn' => 'civicrm_contact']
    )->processForm();

    $this->assertSame(1, $this->countActions());
    $row = CiviRulesRuleAction::get(FALSE)
      ->addSelect('id', 'action_params')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->execute()->first();
    $this->assertSame($existing['id'], $row['id']);
    $this->assertEquals(['tag_id' => [$otherTag]], $row['action_params']);
  }

  public function testAddedActionsGetSequentialWeights(): void {
    foreach ([1, 2] as $ignored) {
      $this->getTestForm('CRM_CivirulesActions_Tag_Form_EntityTag',
        ['tag_id' => [$this->tagId]],
        [
          'rule_id' => $this->ruleId,
          'action_id' => $this->actionTypeId('contact_tag_add'),
          'tn' => 'civicrm_contact',
        ]
      )->processForm();
    }

    $weights = CiviRulesRuleAction::get(FALSE)
      ->addSelect('weight')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->addOrderBy('weight', 'ASC')
      ->execute()->column('weight');
    $this->assertSame([1, 2], $weights);
  }

  /**
   * condition_link is normalised by a hook_civicrm_post listener, which DAO::save()
   * does not fire - the base form has to raise it explicitly.
   */
  public function testAddedConditionsGetTheirConditionLinkNormalised(): void {
    $conditionTypeId = CiviRulesCondition::get(FALSE)->addSelect('id')
      ->addWhere('name', '=', 'contact_has_type')->execute()->first()['id'];

    foreach ([1, 2] as $ignored) {
      $this->getTestForm('CRM_CivirulesConditions_Form_Contact_HasType',
        ['operator' => 'is one of', 'type_names' => ['Individual']],
        ['rule_id' => $this->ruleId, 'condition_id' => $conditionTypeId]
      )->processForm();
    }

    $links = CiviRulesRuleCondition::get(FALSE)
      ->addSelect('condition_link')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->addOrderBy('weight', 'ASC')
      ->execute()->column('condition_link');
    $this->assertCount(2, $links);
    $this->assertNull($links[0], 'The first condition has no link operator');
    $this->assertSame('AND', $links[1]);
  }

}
