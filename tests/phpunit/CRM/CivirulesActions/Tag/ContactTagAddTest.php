<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test CRM_CivirulesActions_Tag_ContactTagAdd.
 *
 * @group headless
 */
class CRM_CivirulesActions_Tag_ContactTagAddTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $contactId;

  private int $tagOneId;

  private int $tagTwoId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->contactId = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'ContactTagAdd')
      ->addValue('last_name', 'PHPUnit')
      ->execute()->first()['id'];
    $this->tagOneId = \Civi\Api4\Tag::create(FALSE)
      ->addValue('name', 'PHPUnit Tag One')
      ->addValue('used_for', ['civicrm_contact'])
      ->execute()->first()['id'];
    $this->tagTwoId = \Civi\Api4\Tag::create(FALSE)
      ->addValue('name', 'PHPUnit Tag Two')
      ->addValue('used_for', ['civicrm_contact'])
      ->execute()->first()['id'];
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  private function getTriggerDataForContact(): CRM_Civirules_TriggerData_TriggerData {
    return new CRM_Civirules_TriggerData_Post('Contact', $this->contactId, []);
  }

  private function getEntityTagsForContact(): array {
    return \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_contact')
      ->addWhere('entity_id', '=', $this->contactId)
      ->execute()
      ->column('tag_id');
  }

  private function newAction(): CRM_CivirulesActions_Tag_ContactTagAdd {
    return new CRM_CivirulesActions_Tag_ContactTagAdd();
  }

  public function testAddsConfiguredTagToContact(): void {
    $action = $this->newAction();
    $action->setRuleActionData(['action_params' => ['tag_id' => [$this->tagOneId]]]);

    $action->processAction($this->getTriggerDataForContact());

    $this->assertEquals([$this->tagOneId], $this->getEntityTagsForContact());
  }

  public function testAddsMultipleConfiguredTagsToContact(): void {
    $action = $this->newAction();
    $action->setRuleActionData(['action_params' => ['tag_id' => [$this->tagOneId, $this->tagTwoId]]]);

    $action->processAction($this->getTriggerDataForContact());

    $appliedTags = $this->getEntityTagsForContact();
    sort($appliedTags);
    $expected = [$this->tagOneId, $this->tagTwoId];
    sort($expected);
    $this->assertEquals($expected, $appliedTags);
  }

  public function testDoesNotAddAnyTagWhenTagIdParamIsMissing(): void {
    $action = $this->newAction();
    $action->setRuleActionData(['action_params' => []]);

    $action->processAction($this->getTriggerDataForContact());

    $this->assertSame([], $this->getEntityTagsForContact());
  }

  public function testDoesNotErrorWhenNoActionDataHasBeenSet(): void {
    $action = $this->newAction();

    $action->processAction($this->getTriggerDataForContact());

    $this->assertSame([], $this->getEntityTagsForContact());
  }

  /**
   * civicrm_entity_tag has a unique key on (entity_id, entity_table, tag_id) and the action
   * does not check for an existing row first, so re-running it for a contact that already
   * has the tag is not idempotent - it throws instead of silently skipping the duplicate.
   */
  public function testThrowsWhenRunTwiceForAContactThatAlreadyHasTheTag(): void {
    $action = $this->newAction();
    $action->setRuleActionData(['action_params' => ['tag_id' => [$this->tagOneId]]]);
    $action->processAction($this->getTriggerDataForContact());

    $this->expectException(CRM_Core_Exception::class);
    $action->processAction($this->getTriggerDataForContact());
  }

  public function testOnlyTagsTheContactPassedInTriggerData(): void {
    $otherContactId = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Other')
      ->addValue('last_name', 'Contact')
      ->execute()->first()['id'];

    $action = $this->newAction();
    $action->setRuleActionData(['action_params' => ['tag_id' => [$this->tagOneId]]]);
    $action->processAction($this->getTriggerDataForContact());

    $otherContactTags = \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_contact')
      ->addWhere('entity_id', '=', $otherContactId)
      ->execute();
    $this->assertCount(0, $otherContactTags);
    $this->assertEquals([$this->tagOneId], $this->getEntityTagsForContact());
  }

}
