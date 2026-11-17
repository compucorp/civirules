<?php

use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Group;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests the contact id that edit trigger data hands to conditions.
 *
 * A partial save, such as an email synced from the CMS with only id and
 * email, has no contact_id in its post data. The contact then comes from the
 * record as it is now in the database or, once the record is deleted, from
 * the data captured before the edit.
 *
 * @group headless
 */
class CRM_Civirules_TriggerData_EditTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * The data from before the edit can be stale, so the current owner wins.
   */
  public function testCurrentOwnerWinsOverOriginalContactId(): void {
    $ownerId = $this->createContact();
    $staleContactId = $this->createContact();
    $emailId = $this->createEmail($ownerId);

    $triggerData = new CRM_Civirules_TriggerData_Edit('Email', $emailId,
      ['id' => $emailId, 'email' => 'new@example.org'],
      ['id' => $emailId, 'contact_id' => $staleContactId, 'email' => 'old@example.org']
    );

    $this->assertSame($ownerId, $triggerData->getContactId());
  }

  /**
   * Once the record is deleted, the contact from before the delete is used.
   */
  public function testFallsBackToOriginalContactIdWhenRecordIsGone(): void {
    $ownerId = $this->createContact();
    $emailId = $this->deletedEmailId($ownerId);

    // Data from before the edit holds ids as strings, as API3 returns them.
    $triggerData = new CRM_Civirules_TriggerData_Edit('Email', $emailId,
      ['id' => $emailId],
      ['id' => $emailId, 'contact_id' => (string) $ownerId, 'email' => 'old@example.org']
    );

    $this->assertSame($ownerId, $triggerData->getContactId());
  }

  /**
   * A record detached from its contact must not resolve to the old one.
   */
  public function testRowWithNullContactIdDoesNotFallBack(): void {
    $formerOwnerId = $this->createContact();
    $emailId = $this->createEmail($formerOwnerId);
    CRM_Core_DAO::executeQuery('UPDATE civicrm_email SET contact_id = NULL WHERE id = %1', [
      1 => [$emailId, 'Integer'],
    ]);

    $triggerData = new CRM_Civirules_TriggerData_Edit('Email', $emailId,
      ['id' => $emailId, 'email' => 'new@example.org'],
      ['id' => $emailId, 'contact_id' => $formerOwnerId, 'email' => 'old@example.org']
    );

    $this->assertNull($triggerData->getContactId());
  }

  /**
   * A contact in the post data wins over the record's owner.
   */
  public function testPrefersContactIdFromPostData(): void {
    $ownerId = $this->createContact();
    $postDataContactId = $this->createContact();
    $emailId = $this->createEmail($ownerId);

    $triggerData = new CRM_Civirules_TriggerData_Edit('Email', $emailId,
      ['id' => $emailId, 'contact_id' => $postDataContactId, 'email' => 'new@example.org'],
      ['id' => $emailId, 'contact_id' => $ownerId, 'email' => 'old@example.org']
    );

    $this->assertSame($postDataContactId, $triggerData->getContactId());
  }

  /**
   * Conditions, actions and a delayed action must all see the same contact.
   */
  public function testFallbackContactIsResolvedOnce(): void {
    $ownerId = $this->createContact();
    $laterOwnerId = $this->createContact();
    $emailId = $this->createEmail($ownerId);
    $triggerData = new CRM_Civirules_TriggerData_Edit('Email', $emailId,
      ['id' => $emailId, 'email' => 'new@example.org'],
      ['id' => $emailId, 'email' => 'old@example.org']
    );

    $this->assertSame($ownerId, $triggerData->getContactId());
    CRM_Core_DAO::executeQuery('UPDATE civicrm_email SET contact_id = %1 WHERE id = %2', [
      1 => [$laterOwnerId, 'Integer'],
      2 => [$emailId, 'Integer'],
    ]);

    $this->assertSame($ownerId, $triggerData->getContactId());
    $this->assertSame($ownerId, unserialize(serialize($triggerData))->getContactId());
  }

  /**
   * Case returns contact_id as an array, which is not a single contact.
   */
  public function testIgnoresOriginalContactIdThatIsAnArray(): void {
    $triggerData = new CRM_Civirules_TriggerData_Edit('Case', 9,
      ['id' => 9, 'subject' => 'Changed'],
      ['id' => 9, 'contact_id' => [1 => 42], 'subject' => 'Original']
    );

    $this->assertNull($triggerData->getContactId());
  }

  /**
   * A group has no contact anywhere, so the lookup returns NULL, not an error.
   */
  public function testGroupWithoutAnyContactReturnsNullWithoutError(): void {
    $groupId = Group::create(FALSE)
      ->setValues(['title' => 'CiviRules edit test', 'name' => 'civirules_edit_test'])
      ->execute()->first()['id'];

    $triggerData = new CRM_Civirules_TriggerData_Edit('Group', $groupId,
      ['id' => $groupId, 'title' => 'CiviRules edit test changed'],
      ['id' => $groupId, 'title' => 'CiviRules edit test']
    );

    $this->assertNull($triggerData->getContactId());
  }

  public function testReturnsNullWithoutAnyContactId(): void {
    $emailId = $this->deletedEmailId($this->createContact());

    $triggerData = new CRM_Civirules_TriggerData_Edit('Email', $emailId,
      ['id' => $emailId, 'email' => 'new@example.org'],
      ['id' => $emailId, 'email' => 'old@example.org']
    );

    $this->assertNull($triggerData->getContactId());
  }

  public function testExplicitContactIdWins(): void {
    $emailId = $this->createEmail($this->createContact());

    $triggerData = new CRM_Civirules_TriggerData_Edit('Email', $emailId,
      ['id' => $emailId, 'email' => 'new@example.org'],
      ['id' => $emailId, 'contact_id' => 42, 'email' => 'old@example.org']
    );
    $triggerData->setContactId(99);

    $this->assertEquals(99, $triggerData->getContactId());
  }

  /**
   * Creates an individual and returns its id.
   */
  private function createContact(): int {
    return Contact::create(FALSE)
      ->setValues(['contact_type' => 'Individual', 'first_name' => 'Edit', 'last_name' => 'TriggerDataTest'])
      ->execute()->first()['id'];
  }

  /**
   * Creates an email for the contact and returns its id.
   */
  private function createEmail(int $contactId): int {
    return Email::create(FALSE)
      ->setValues(['contact_id' => $contactId, 'email' => 'edit.test@example.org'])
      ->execute()->first()['id'];
  }

  /**
   * Creates an email for the contact, deletes it and returns its id.
   */
  private function deletedEmailId(int $contactId): int {
    $emailId = $this->createEmail($contactId);
    Email::delete(FALSE)->addWhere('id', '=', $emailId)->execute();
    return $emailId;
  }

}
