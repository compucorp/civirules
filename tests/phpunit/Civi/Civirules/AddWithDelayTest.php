<?php

namespace Civi\Civirules;

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Tag;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\FormTrait;
use Civi\Test\FormWrapper;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * A delay can be set while adding an action.
 *
 * The delay used to live on the type picker, which the configuration form now
 * replaces for anything configurable.
 *
 * @group headless
 */
class AddWithDelayTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

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
    unset($_GET['rule_action_id'], $_POST['rule_action_id'], $_REQUEST['rule_action_id']);
    $this->ruleId = CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'phpunit_add_delay',
        'name' => 'phpunit_add_delay',
        'trigger_id' => CiviRulesTrigger::get(FALSE)->addSelect('id')->setLimit(1)->execute()->first()['id'],
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
    $this->tagId = Tag::create(FALSE)
      ->addValue('name', 'PHPUnit Delay Tag')
      ->addValue('used_for', ['civicrm_contact'])
      ->execute()->first()['id'];
  }

  /**
   * A browser submits every element on the form, including the type picker and the
   * always-required entity/field selects that one of the delay classes contributes.
   *
   * @param array $extraValues
   *
   * @return \Civi\Test\FormWrapper
   */
  private function addTagActionForm(array $extraValues = []): FormWrapper {
    $actionTypeId = CiviRulesAction::get(FALSE)->addSelect('id')
      ->addWhere('name', '=', 'contact_tag_add')->execute()->first()['id'];
    return $this->getTestForm('CRM_CivirulesActions_Tag_Form_EntityTag',
      [
        'tag_id' => [$this->tagId],
        'rule_action_select' => $actionTypeId,
        'entity' => 'Activity',
        'field' => 'activity_date_time',
      ] + $extraValues,
      [
        'rule_id' => $this->ruleId,
        'action_id' => $actionTypeId,
        'tn' => 'civicrm_contact',
      ]
    );
  }

  private function getRuleAction(): array {
    return (array) CiviRulesRuleAction::get(FALSE)
      ->addSelect('id', 'delay', 'ignore_condition_with_delay', 'action_params')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->execute()->first();
  }

  public function testDelayChosenWhileAddingIsStored(): void {
    $this->addTagActionForm([
      'delay_select' => 'CRM_Civirules_Delay_XDays',
      'xdays_dayOffset' => 5,
      'ignore_condition_with_delay' => 1,
    ])->processForm();

    $row = $this->getRuleAction();
    $this->assertNotEmpty($row['delay']);
    $delay = is_string($row['delay']) ? unserialize($row['delay']) : $row['delay'];
    $this->assertInstanceOf(\CRM_Civirules_Delay_XDays::class, $delay);
    $this->assertStringContainsString('5', $delay->getDelayExplanation());
    $this->assertEquals(1, $row['ignore_condition_with_delay']);
    // The action's own configuration must survive the extra save the delay needs.
    $this->assertEquals(['tag_id' => [$this->tagId]], $row['action_params']);
  }

  public function testAddingWithoutADelayLeavesItUnset(): void {
    $this->addTagActionForm()->processForm();

    $row = $this->getRuleAction();
    $this->assertEmpty($row['delay']);
    $this->assertEquals(['tag_id' => [$this->tagId]], $row['action_params']);
  }

  /**
   * The delay's own validation has to work before the rule action exists, which is
   * what it used to look the rule up through.
   */
  public function testAnIncompleteDelayFailsValidationAndCreatesNothing(): void {
    $this->addTagActionForm([
      'delay_select' => 'CRM_Civirules_Delay_XDays',
      // No day offset given.
      'xdays_dayOffset' => '',
    ])->processForm(FormWrapper::VALIDATED);

    $this->assertValidationError(['xdays_dayOffset' => 'You need to provide a number of days']);
    $this->assertSame(0, CiviRulesRuleAction::get(FALSE)
      ->addWhere('rule_id', '=', $this->ruleId)->execute()->count());
  }

}
