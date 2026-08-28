<?php

namespace Civi\Civirules;

use Civi\Api4\Activity;
use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Contact;
use Civi\Api4\EntityTag;
use Civi\Api4\Tag;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * How the engine treats a rule action/condition that was added but never configured.
 *
 * Rules are driven end-to-end through a real trigger, because CRM_Civirules_Engine
 * swallows every Throwable - so the only trustworthy assertion is whether the rule's
 * action actually had an effect.
 *
 * @group headless
 */
class UnconfiguredRuleItemTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $ruleId;

  private int $tagId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    \CRM_Civirules_Utils_ConfigurationStatus::flushCache();
    \Civi::$statics['CRM_Civirules_Engine']['unconfigured'] = [];

    $this->tagId = Tag::create(FALSE)
      ->addValue('name', 'PHPUnit Unconfigured Tag')
      ->addValue('used_for', ['civicrm_contact'])
      ->execute()->first()['id'];

    $this->ruleId = CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'PHPUnit Unconfigured Rule',
        'name' => 'phpunit_unconfigured_rule',
        'trigger_id' => CiviRulesTrigger::get(FALSE)->addWhere('name', '=', 'trashed_contact')->execute()->first()['id'],
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
  }

  private function addTagAction(): int {
    return CiviRulesRuleAction::create(FALSE)
      ->setValues([
        'rule_id' => $this->ruleId,
        'action_id' => CiviRulesAction::get(FALSE)->addWhere('name', '=', 'contact_tag_add')->execute()->first()['id'],
        'action_params' => ['tag_id' => [$this->tagId]],
        'is_active' => TRUE,
        'weight' => 10,
      ])
      ->execute()->first()['id'];
  }

  /**
   * @param string $conditionName
   * @param string|null $link
   * @param array|null $params
   *   NULL leaves condition_params unset, i.e. never configured.
   * @param int $weight
   *
   * @return int
   */
  private function addCondition(string $conditionName, ?string $link, ?array $params, int $weight): int {
    $values = [
      'rule_id' => $this->ruleId,
      'condition_id' => CiviRulesCondition::get(FALSE)->addWhere('name', '=', $conditionName)->execute()->first()['id'],
      'condition_link' => $link,
      'is_active' => TRUE,
      'weight' => $weight,
    ];
    if ($params !== NULL) {
      $values['condition_params'] = $params;
    }
    return CiviRulesRuleCondition::create(FALSE)->setValues($values)->execute()->first()['id'];
  }

  /**
   * Trash a contact, which fires the rule.
   */
  private function fireRule(): int {
    $contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Unconfigured')
      ->addValue('last_name', 'PHPUnit')
      ->execute()->first()['id'];

    $dao = new \CRM_Contact_DAO_Contact();
    $dao->id = $contactId;
    $dao->find(TRUE);
    $dao->is_deleted = 1;

    $trigger = new \CRM_CivirulesPostTrigger_ContactTrashed();
    $trigger->setRuleId($this->ruleId);
    $trigger->triggerTrigger('update', 'Individual', $contactId, $dao, NULL);

    return $contactId;
  }

  private function actionRan(int $contactId): bool {
    return (bool) EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_contact')
      ->addWhere('entity_id', '=', $contactId)
      ->addWhere('tag_id', '=', $this->tagId)
      ->execute()
      ->count();
  }

  /**
   * The important one. areConditionsValid() starts from $isValid = TRUE and only
   * assigns inside its loop, so an implementation that dropped unconfigured
   * conditions from the list would leave this rule with no conditions at all and
   * fire it on every trigger.
   */
  public function testRuleWithOnlyAnUnconfiguredConditionNeverFires(): void {
    $this->addCondition('contact_in_group', NULL, NULL, 1);
    $this->addTagAction();

    $contactId = $this->fireRule();

    $this->assertFalse($this->actionRan($contactId));
  }

  public function testUnconfiguredConditionBlocksAnAndChain(): void {
    $this->addCondition('contact_in_group', NULL, NULL, 1);
    $this->addCondition('contact_has_type', 'AND', ['operator' => 'is one of', 'type_names' => ['Individual']], 2);
    $this->addTagAction();

    $contactId = $this->fireRule();

    $this->assertFalse($this->actionRan($contactId));
  }

  /**
   * An unconfigured condition must be merely false, not fatal: a true OR branch
   * after it still has to be reached.
   */
  public function testUnconfiguredConditionDoesNotBlockATrueOrBranch(): void {
    $this->addCondition('contact_in_group', NULL, NULL, 1);
    $this->addCondition('contact_has_type', 'OR', ['operator' => 'is one of', 'type_names' => ['Individual']], 2);
    $this->addTagAction();

    $contactId = $this->fireRule();

    $this->assertTrue($this->actionRan($contactId));
  }

  /**
   * A configured rule is unaffected.
   */
  public function testConfiguredConditionStillFires(): void {
    $this->addCondition('contact_has_type', NULL, ['operator' => 'is one of', 'type_names' => ['Individual']], 1);
    $this->addTagAction();

    $contactId = $this->fireRule();

    $this->assertTrue($this->actionRan($contactId));
  }

  public function testConfiguredConditionThatDoesNotMatchDoesNotFire(): void {
    $this->addCondition('contact_has_type', NULL, ['operator' => 'is not one of', 'type_names' => ['Individual']], 1);
    $this->addTagAction();

    $contactId = $this->fireRule();

    $this->assertFalse($this->actionRan($contactId));
  }

  /**
   * An unconfigured action is skipped entirely rather than run with no parameters,
   * and does not stop the rest of the rule.
   */
  public function testUnconfiguredActionIsSkippedAndLaterActionsStillRun(): void {
    $unconfigured = CiviRulesRuleAction::create(FALSE)
      ->setValues([
        'rule_id' => $this->ruleId,
        'action_id' => CiviRulesAction::get(FALSE)->addWhere('name', '=', 'activity_add')->execute()->first()['id'],
        'is_active' => TRUE,
        'weight' => 1,
      ])
      ->execute()->first()['id'];
    $this->addTagAction();

    $contactId = $this->fireRule();

    $this->assertSame(0, Activity::get(FALSE)
      ->addWhere('source_contact_id', '=', $contactId)
      ->execute()->count(), 'An unconfigured "add activity" must not create an activity');
    $this->assertTrue($this->actionRan($contactId), 'The configured action must still run');
    $this->assertFalse((bool) CiviRulesRuleAction::get(FALSE)
      ->addSelect('is_configured')
      ->addWhere('id', '=', $unconfigured)
      ->execute()->first()['is_configured']);
  }

}
