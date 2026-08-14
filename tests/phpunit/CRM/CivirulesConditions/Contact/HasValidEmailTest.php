<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test CRM_CivirulesConditions_Contact_HasValidEmail.
 *
 * @group headless
 */
class CRM_CivirulesConditions_Contact_HasValidEmailTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $contactId;

  private CRM_CivirulesConditions_Contact_HasValidEmail $condition;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    $this->contactId = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'HasValidEmail')
      ->addValue('last_name', 'PHPUnit')
      ->execute()->first()['id'];
    $this->condition = new CRM_CivirulesConditions_Contact_HasValidEmail();
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  private function getTriggerDataForContact(int $contactId): CRM_Civirules_TriggerData_TriggerData {
    return new CRM_Civirules_TriggerData_Post('Contact', $contactId, []);
  }

  public function testReturnsTrueWhenContactHasPrimaryEmailNotOnHold(): void {
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('email', 'valid@example.org')
      ->addValue('is_primary', TRUE)
      ->addValue('on_hold', 0)
      ->execute();

    $this->assertTrue($this->condition->isConditionValid($this->getTriggerDataForContact($this->contactId)));
  }

  public function testReturnsFalseWhenContactHasNoEmailAtAll(): void {
    $this->assertFalse($this->condition->isConditionValid($this->getTriggerDataForContact($this->contactId)));
  }

  public function testReturnsFalseWhenPrimaryEmailIsOnHold(): void {
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('email', 'onhold@example.org')
      ->addValue('is_primary', TRUE)
      ->addValue('on_hold', 1)
      ->execute();

    $this->assertFalse($this->condition->isConditionValid($this->getTriggerDataForContact($this->contactId)));
  }

  /**
   * CRM_Core_BAO_Block::handlePrimary() always forces a contact's sole email to be
   * primary, so a lone non-primary email can't exist - this instead proves that a
   * second, perfectly valid, non-primary email does NOT count: only the (on-hold)
   * primary email is consulted.
   */
  public function testReturnsFalseWhenPrimaryEmailIsOnHoldEvenIfAnotherValidEmailExists(): void {
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('email', 'primary-onhold@example.org')
      ->addValue('is_primary', TRUE)
      ->addValue('on_hold', 1)
      ->execute();
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('email', 'secondary-valid@example.org')
      ->addValue('is_primary', FALSE)
      ->addValue('on_hold', 0)
      ->execute();

    $this->assertFalse($this->condition->isConditionValid($this->getTriggerDataForContact($this->contactId)));
  }

  public function testReturnsTrueWhenValidPrimaryEmailCoexistsWithOnHoldSecondaryEmail(): void {
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('email', 'primary@example.org')
      ->addValue('is_primary', TRUE)
      ->addValue('on_hold', 0)
      ->execute();
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $this->contactId)
      ->addValue('email', 'secondary-onhold@example.org')
      ->addValue('is_primary', FALSE)
      ->addValue('on_hold', 1)
      ->execute();

    $this->assertTrue($this->condition->isConditionValid($this->getTriggerDataForContact($this->contactId)));
  }

  public function testIsIndifferentToOtherContactsEmails(): void {
    $otherContactId = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Other')
      ->addValue('last_name', 'Contact')
      ->execute()->first()['id'];
    \Civi\Api4\Email::create(FALSE)
      ->addValue('contact_id', $otherContactId)
      ->addValue('email', 'other-contact@example.org')
      ->addValue('is_primary', TRUE)
      ->addValue('on_hold', 0)
      ->execute();

    // The contact under test still has no email of its own.
    $this->assertFalse($this->condition->isConditionValid($this->getTriggerDataForContact($this->contactId)));
  }

  public function testGetExtraDataInputUrlReturnsFalseAsConditionHasNoConfiguration(): void {
    $this->assertFalse($this->condition->getExtraDataInputUrl(1));
  }

  public function testDoesWorkWithTriggerThatProvidesAContact(): void {
    // object_name must be set (as it would be when the trigger is loaded from the
    // civirule_trigger row) - reactOnEntity() relies on it to report which entity
    // the trigger provides.
    $trigger = new CRM_CivirulesPostTrigger_ContactTrashed(['id' => 1, 'name' => 'trashed_contact', 'object_name' => 'Contact', 'op' => 'update']);
    $rule = new CRM_Civirules_BAO_CiviRulesRule();
    $this->assertTrue($this->condition->doesWorkWithTrigger($trigger, $rule));
  }

}
