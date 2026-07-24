<?php

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;

/**
 * Test CRM_Civirules_Delay_DelayBasedOnDateField.
 *
 * Note: this test deliberately does NOT implement TransactionalInterface.
 * Creating a custom group performs DDL (CREATE TABLE), which causes an
 * implicit commit in MySQL and would break the transaction-and-rollback
 * cleanup. Cleanup is done explicitly in setUp()/tearDown() instead.
 *
 * @group headless
 */
class CRM_CivirulesActions_Delay_DelayBasedOnDateFieldTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface {

  private const CASE_TYPE_NAME = 'phpunit_delay_case_type';
  private const CASE_GROUP_NAME = 'phpunit_delay_case_dates';
  private const CONTACT_GROUP_NAME = 'phpunit_delay_contact_dates';

  private int $contactId;

  private int $caseTypeId;

  private int $caseId;

  private int $caseCustomFieldId;

  private int $contactCustomFieldId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    CRM_Core_BAO_ConfigSetting::enableComponent('CiviCase');
    // Defensive cleanup in case a previous run aborted before tearDown().
    $this->deleteTestData();

    $this->contactId = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'DelayBasedOnDateField')
      ->addValue('last_name', 'PHPUnit')
      ->execute()->first()['id'];

    // Created via api3: only the BAO add() used by api3 converts a definition
    // array to XML. A definition is needed because Case.create (api4) runs the
    // case type timeline through the XML processor, which throws for a case
    // type without one.
    $caseType = civicrm_api3('CaseType', 'create', [
      'name' => self::CASE_TYPE_NAME,
      'title' => 'PHPUnit Delay Case Type',
      'is_active' => 1,
      'definition' => [
        'activityTypes' => [
          ['name' => 'Open Case', 'max_instances' => 1],
        ],
        'activitySets' => [
          [
            'name' => 'standard_timeline',
            'label' => 'Standard Timeline',
            'timeline' => 1,
            'activityTypes' => [
              ['name' => 'Open Case', 'status' => 'Completed'],
            ],
          ],
        ],
      ],
    ]);
    $this->caseTypeId = (int) $caseType['id'];

    $this->caseId = \Civi\Api4\CiviCase::create(FALSE)
      ->addValue('case_type_id', $this->caseTypeId)
      // contact_id (case client) and creator_id are required by the Case
      // creation spec. creator_id normally defaults to the logged-in user,
      // which does not exist in a headless test run.
      ->addValue('contact_id', $this->contactId)
      ->addValue('creator_id', $this->contactId)
      ->addValue('subject', 'PHPUnit delay case')
      ->addValue('status_id:name', 'Open')
      ->addValue('start_date', date('Y-m-d'))
      ->execute()->first()['id'];

    $this->caseCustomFieldId = $this->createDateCustomField(self::CASE_GROUP_NAME, 'Case', 'phpunit_case_date');
    $this->contactCustomFieldId = $this->createDateCustomField(self::CONTACT_GROUP_NAME, 'Contact', 'phpunit_contact_date');
  }

  public function tearDown(): void {
    $this->deleteTestData();
    parent::tearDown();
  }

  private function createDateCustomField(string $groupName, string $extends, string $fieldName): int {
    \Civi\Api4\CustomGroup::create(FALSE)
      ->addValue('name', $groupName)
      ->addValue('title', $groupName)
      ->addValue('extends', $extends)
      ->addValue('is_active', TRUE)
      ->execute();
    return \Civi\Api4\CustomField::create(FALSE)
      ->addValue('custom_group_id:name', $groupName)
      ->addValue('name', $fieldName)
      ->addValue('label', $fieldName)
      ->addValue('data_type', 'Date')
      ->addValue('html_type', 'Select Date')
      ->addValue('is_active', TRUE)
      ->execute()->first()['id'];
  }

  private function deleteTestData(): void {
    foreach ([self::CASE_GROUP_NAME, self::CONTACT_GROUP_NAME] as $groupName) {
      try {
        \Civi\Api4\CustomGroup::delete(FALSE)
          ->addWhere('name', '=', $groupName)
          ->execute();
      }
      catch (Throwable $ex) {
      }
    }
    try {
      // Delete timeline activities created by Case.create, then the cases.
      $caseIds = \Civi\Api4\CiviCase::get(FALSE)
        ->addWhere('case_type_id.name', '=', self::CASE_TYPE_NAME)
        ->execute()->column('id');
      if ($caseIds) {
        $activityIds = \Civi\Api4\CaseActivity::get(FALSE)
          ->addWhere('case_id', 'IN', $caseIds)
          ->execute()->column('activity_id');
        if ($activityIds) {
          \Civi\Api4\Activity::delete(FALSE)
            ->addWhere('id', 'IN', $activityIds)
            ->execute();
        }
      }
      \Civi\Api4\CiviCase::delete(FALSE)
        ->addWhere('case_type_id.name', '=', self::CASE_TYPE_NAME)
        ->execute();
      \Civi\Api4\CaseType::delete(FALSE)
        ->addWhere('name', '=', self::CASE_TYPE_NAME)
        ->execute();
    }
    catch (Throwable $ex) {
    }
    try {
      \Civi\Api4\Contact::delete(FALSE)
        ->addWhere('last_name', '=', 'PHPUnit')
        ->addWhere('first_name', '=', 'DelayBasedOnDateField')
        ->setUseTrash(FALSE)
        ->execute();
    }
    catch (Throwable $ex) {
    }
  }

  /**
   * Build a configured delay, the same way the rule action form stores it.
   */
  private function newDelay(string $modifier, string $amount, string $unit, string $entity, string $field): CRM_Civirules_Delay_DelayBasedOnDateField {
    $delay = new CRM_Civirules_Delay_DelayBasedOnDateField();
    $delay->setValues([
      'modifier' => $modifier,
      'amount' => $amount,
      'unit' => $unit,
      'entity' => $entity,
      'field' => $field,
    ], '', new CRM_Civirules_BAO_CiviRulesRule());
    return $delay;
  }

  private function getTriggerDataForCase(): CRM_Civirules_TriggerData_TriggerData {
    // Mimic the data available in a post hook for a Case entity.
    return new CRM_Civirules_TriggerData_Post('Case', $this->caseId, [
      'id' => $this->caseId,
      'contact_id' => [$this->contactId],
    ]);
  }

  /**
   * Reproduction of the reported bug: an activity date delay based on a Case
   * custom date field must be calculated from that field, not default to now.
   */
  public function testDelayBasedOnCaseCustomDateField(): void {
    \Civi\Api4\CiviCase::update(FALSE)
      ->addWhere('id', '=', $this->caseId)
      ->addValue(self::CASE_GROUP_NAME . '.phpunit_case_date', '2025-03-10')
      ->execute();

    $delay = $this->newDelay('+', '3', 'days', 'Case', 'Case_custom_' . $this->caseCustomFieldId);
    $result = $delay->delayTo(new DateTime(), $this->getTriggerDataForCase());

    $this->assertEquals('2025-03-13', $result->format('Y-m-d'));
  }

  public function testDelayBeforeCaseCustomDateField(): void {
    \Civi\Api4\CiviCase::update(FALSE)
      ->addWhere('id', '=', $this->caseId)
      ->addValue(self::CASE_GROUP_NAME . '.phpunit_case_date', '2025-03-10')
      ->execute();

    $delay = $this->newDelay('-', '1', 'weeks', 'Case', 'Case_custom_' . $this->caseCustomFieldId);
    $result = $delay->delayTo(new DateTime(), $this->getTriggerDataForCase());

    $this->assertEquals('2025-03-03', $result->format('Y-m-d'));
  }

  /**
   * Guard against regressions on the path that already worked before the fix.
   */
  public function testDelayBasedOnContactCustomDateField(): void {
    \Civi\Api4\Contact::update(FALSE)
      ->addWhere('id', '=', $this->contactId)
      ->addValue(self::CONTACT_GROUP_NAME . '.phpunit_contact_date', '2030-01-15')
      ->execute();

    $delay = $this->newDelay('+', '5', 'days', 'Contact', 'Contact_custom_' . $this->contactCustomFieldId);
    $triggerData = new CRM_Civirules_TriggerData_Post('Contact', $this->contactId, ['id' => $this->contactId]);
    $result = $delay->delayTo(new DateTime(), $triggerData);

    $this->assertEquals('2030-01-20', $result->format('Y-m-d'));
  }

  /**
   * When the custom date field has no value the delay must fall back to the
   * date passed in (the pre-existing behaviour).
   */
  public function testFallsBackToPassedDateWhenCustomFieldIsEmpty(): void {
    $now = new DateTime('2025-06-01 12:00:00');

    $delay = $this->newDelay('+', '3', 'days', 'Case', 'Case_custom_' . $this->caseCustomFieldId);
    $result = $delay->delayTo($now, $this->getTriggerDataForCase());

    $this->assertEquals('2025-06-01 12:00:00', $result->format('Y-m-d H:i:s'));
  }

}
