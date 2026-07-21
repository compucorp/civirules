<?php

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Contact;
use Civi\Api4\EntityTag;
use Civi\Api4\Tag;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test CRM_CivirulesPostTrigger_ContactTrashed.
 *
 * The trigger's own logic is a single guard clause - it should only fire the
 * rule engine (and therefore run the rule's actions) when the objectRef passed
 * in from the post hook is actually flagged as deleted. This exercises that
 * guard end-to-end via a real rule + action, rather than a mock, so it also
 * proves the trigger resolves the correct contact/entity id for the trigger data.
 *
 * @group headless
 */
class CRM_CivirulesPostTrigger_ContactTrashedTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $ruleId;

  private int $tagId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    $triggerTypeId = CiviRulesTrigger::get(FALSE)
      ->addWhere('name', '=', 'trashed_contact')
      ->execute()->first()['id'];
    $actionTypeId = CiviRulesAction::get(FALSE)
      ->addWhere('name', '=', 'contact_tag_add')
      ->execute()->first()['id'];

    $this->tagId = Tag::create(FALSE)
      ->addValue('name', 'PHPUnit Trashed Tag')
      ->addValue('used_for', ['civicrm_contact'])
      ->execute()->first()['id'];

    $this->ruleId = CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'PHPUnit Contact Trashed Rule',
        'name' => 'phpunit_contact_trashed_rule',
        'trigger_id' => $triggerTypeId,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];

    CiviRulesRuleAction::create(FALSE)
      ->setValues([
        'rule_id' => $this->ruleId,
        'action_id' => $actionTypeId,
        'action_params' => ['tag_id' => [$this->tagId]],
        'is_active' => TRUE,
      ])
      ->execute();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  private function createContact(string $firstName): int {
    return Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', $firstName)
      ->addValue('last_name', 'PHPUnit')
      ->execute()->first()['id'];
  }

  private function loadContactDao(int $contactId): CRM_Contact_DAO_Contact {
    $dao = new CRM_Contact_DAO_Contact();
    $dao->id = $contactId;
    $dao->find(TRUE);
    return $dao;
  }

  private function hasTagApplied(int $contactId): bool {
    return (bool) EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_contact')
      ->addWhere('entity_id', '=', $contactId)
      ->addWhere('tag_id', '=', $this->tagId)
      ->execute()
      ->count();
  }

  private function newTrigger(): CRM_CivirulesPostTrigger_ContactTrashed {
    $trigger = new CRM_CivirulesPostTrigger_ContactTrashed();
    $trigger->setRuleId($this->ruleId);
    return $trigger;
  }

  public function testFiresRuleWhenObjectRefIsMarkedDeleted(): void {
    $contactId = $this->createContact('Trashed');
    $dao = $this->loadContactDao($contactId);
    $dao->is_deleted = 1;

    $this->newTrigger()->triggerTrigger('update', 'Individual', $contactId, $dao, NULL);

    $this->assertTrue($this->hasTagApplied($contactId));
  }

  public function testDoesNotFireRuleWhenObjectRefIsNotMarkedDeleted(): void {
    $contactId = $this->createContact('NotTrashed');
    $dao = $this->loadContactDao($contactId);
    $dao->is_deleted = 0;

    $this->newTrigger()->triggerTrigger('update', 'Individual', $contactId, $dao, NULL);

    $this->assertFalse($this->hasTagApplied($contactId));
  }

  public function testOnlyTagsTheContactThatWasActuallyTrashed(): void {
    $trashedContactId = $this->createContact('Trashed');
    $untouchedContactId = $this->createContact('Untouched');

    $dao = $this->loadContactDao($trashedContactId);
    $dao->is_deleted = 1;
    $this->newTrigger()->triggerTrigger('update', 'Individual', $trashedContactId, $dao, NULL);

    $this->assertTrue($this->hasTagApplied($trashedContactId));
    $this->assertFalse($this->hasTagApplied($untouchedContactId));
  }

  public function testDoesNotFireTheActionAgainWhenTheRuleActionIsDisabled(): void {
    CiviRulesRuleAction::update(FALSE)
      ->addWhere('rule_id', '=', $this->ruleId)
      ->setValues(['is_active' => FALSE])
      ->execute();

    $contactId = $this->createContact('Trashed');
    $dao = $this->loadContactDao($contactId);
    $dao->is_deleted = 1;

    $this->newTrigger()->triggerTrigger('update', 'Individual', $contactId, $dao, NULL);

    $this->assertFalse($this->hasTagApplied($contactId));
  }

}
