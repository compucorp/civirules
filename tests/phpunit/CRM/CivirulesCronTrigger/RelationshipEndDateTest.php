<?php

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Contact;
use Civi\Api4\Relationship;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for the "Relationship end date reached" cron trigger
 * (CRM_CivirulesCronTrigger_RelationshipEndDate), covering both the
 * windowed query (interval trigger params configured) and the legacy
 * query (no trigger params).
 *
 * The trigger's protected getNextEntityTriggerData() is exercised directly
 * via reflection so only the trigger's own entity selection is under test,
 * without involving the rule engine, conditions or actions.
 *
 * @group headless
 */
class CRM_CivirulesCronTrigger_RelationshipEndDateTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $ruleId;

  private int $relationshipTypeId;

  private int $contactB;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    $this->ruleId = $this->createRule('phpunit_relationship_end_date_rule');

    $this->relationshipTypeId = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_relationship_type ORDER BY id LIMIT 1"
    );
    $this->contactB = $this->createContact('Target');
  }

  /**
   * Relationships outside the configured window must not be picked up.
   */
  public function testWindowedQueryOnlyPicksRelationshipsWithinWindow(): void {
    $inWindow = $this->createEndedRelationship($this->createContact('InWindow'), 5);
    $this->createEndedRelationship($this->createContact('OutOfWindow'), 40);

    $ids = $this->getTriggeredRelationshipIds(['interval' => 30, 'interval_unit' => 'days']);

    $this->assertEquals([$inWindow], $ids);
  }

  /**
   * The window boundary is inclusive: with a 1 week window, a relationship
   * that ended exactly 7 days ago is included, 8 days ago is not.
   */
  public function testWindowedQueryBoundaryIsInclusive(): void {
    $onBoundary = $this->createEndedRelationship($this->createContact('OnBoundary'), 7);
    $this->createEndedRelationship($this->createContact('PastBoundary'), 8);

    $ids = $this->getTriggeredRelationshipIds(['interval' => 1, 'interval_unit' => 'weeks']);

    $this->assertEquals([$onBoundary], $ids);
  }

  /**
   * A relationship already logged for this rule on/after its end date must
   * not fire again, but the same log row must not block other rules.
   */
  public function testWindowedQueryExcludesAlreadyTriggeredRelationship(): void {
    $contactA = $this->createContact('AlreadyTriggered');
    $relationshipId = $this->createEndedRelationship($contactA, 5);
    $this->createRuleLog($this->ruleId, $contactA, $relationshipId, 0);

    $ids = $this->getTriggeredRelationshipIds(['interval' => 30, 'interval_unit' => 'days']);
    $this->assertEquals([], $ids, 'Relationship already logged for this rule should not fire again');

    // A log row belonging to a different rule must not exclude it.
    CRM_Core_DAO::executeQuery("DELETE FROM civirule_rule_log WHERE rule_id = %1", [
      1 => [$this->ruleId, 'Integer'],
    ]);
    $otherRuleId = $this->createRule('phpunit_other_rule');
    $this->createRuleLog($otherRuleId, $contactA, $relationshipId, 0);

    $ids = $this->getTriggeredRelationshipIds(['interval' => 30, 'interval_unit' => 'days']);
    $this->assertEquals([$relationshipId], $ids, 'Log rows of other rules should not block this rule');
  }

  /**
   * A relationship whose end date was extended past its last log entry
   * becomes eligible again once the new end date is reached.
   */
  public function testWindowedQueryRefiresWhenEndDateWasExtended(): void {
    $contactA = $this->createContact('Extended');
    // Ended 10 days ago, but the rule last fired 20 days ago (before the
    // current end date), i.e. the end date was pushed out after firing.
    $relationshipId = $this->createEndedRelationship($contactA, 10);
    $this->createRuleLog($this->ruleId, $contactA, $relationshipId, 20);

    $ids = $this->getTriggeredRelationshipIds(['interval' => 30, 'interval_unit' => 'days']);

    $this->assertEquals([$relationshipId], $ids);
  }

  /**
   * Unlike the legacy query, the windowed query fires for every ended
   * relationship of a contact, not only the most recently ended one per
   * relationship type.
   */
  public function testWindowedQueryPicksAllEndedRelationshipsOfContact(): void {
    $contactA = $this->createContact('MultiRelationship');
    $first = $this->createEndedRelationship($contactA, 5);
    $second = $this->createEndedRelationship($contactA, 10, $this->createContact('OtherTarget'));

    $ids = $this->getTriggeredRelationshipIds(['interval' => 30, 'interval_unit' => 'days']);

    sort($ids);
    $this->assertEquals([$first, $second], $ids);
  }

  /**
   * Without trigger params the legacy query runs: no lower bound on the
   * end date.
   */
  public function testLegacyQueryUsedWhenNoIntervalConfigured(): void {
    $relationshipId = $this->createEndedRelationship($this->createContact('Legacy'), 400);

    $ids = $this->getTriggeredRelationshipIds([]);

    $this->assertEquals([$relationshipId], $ids);
  }

  /**
   * Invalid trigger params (zero interval, unknown unit) must fall back to
   * the legacy query instead of producing a broken window.
   */
  public function testLegacyQueryUsedWhenIntervalParamsAreInvalid(): void {
    $relationshipId = $this->createEndedRelationship($this->createContact('InvalidParams'), 400);

    $ids = $this->getTriggeredRelationshipIds(['interval' => 0, 'interval_unit' => 'days']);
    $this->assertEquals([$relationshipId], $ids, 'Zero interval should fall back to legacy behaviour');

    $ids = $this->getTriggeredRelationshipIds(['interval' => 30, 'interval_unit' => 'fortnights']);
    $this->assertEquals([$relationshipId], $ids, 'Unknown interval unit should fall back to legacy behaviour');
  }

  /**
   * Legacy de-duplication: a contact logged today for this rule is skipped,
   * a contact logged yesterday is not.
   */
  public function testLegacyQueryExcludesContactLoggedToday(): void {
    $contactA = $this->createContact('LoggedToday');
    $relationshipId = $this->createEndedRelationship($contactA, 5);

    $this->createRuleLog($this->ruleId, $contactA, NULL, 0);
    $this->assertEquals([], $this->getTriggeredRelationshipIds([]), 'Contact logged today should be skipped');

    CRM_Core_DAO::executeQuery("DELETE FROM civirule_rule_log WHERE rule_id = %1", [
      1 => [$this->ruleId, 'Integer'],
    ]);
    $this->createRuleLog($this->ruleId, $contactA, NULL, 1);
    $this->assertEquals([$relationshipId], $this->getTriggeredRelationshipIds([]), 'Contact logged yesterday should fire again');
  }

  /**
   * Runs the trigger and collects the relationship ids it yields.
   *
   * @param array $triggerParams
   * @return array
   */
  private function getTriggeredRelationshipIds(array $triggerParams): array {
    $trigger = new CRM_CivirulesCronTrigger_RelationshipEndDate();
    $trigger->setRuleId($this->ruleId);
    $trigger->setTriggerParams(serialize($triggerParams));

    $method = new ReflectionMethod(CRM_CivirulesCronTrigger_RelationshipEndDate::class, 'getNextEntityTriggerData');
    $method->setAccessible(TRUE);

    $ids = [];
    while ($triggerData = $method->invoke($trigger)) {
      $ids[] = (int) $triggerData->getEntityData('Relationship')['id'];
    }
    return $ids;
  }

  /**
   * @param string $firstName
   * @return int
   */
  private function createContact(string $firstName): int {
    return Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', $firstName)
      ->addValue('last_name', 'RelationshipEndDateTest')
      ->execute()->first()['id'];
  }

  /**
   * Creates a relationship that ended $daysAgo days ago.
   *
   * @param int $contactA
   * @param int $daysAgo
   * @param int|null $contactB
   * @return int
   */
  private function createEndedRelationship(int $contactA, int $daysAgo, ?int $contactB = NULL): int {
    return Relationship::create(FALSE)
      ->addValue('contact_id_a', $contactA)
      ->addValue('contact_id_b', $contactB ?? $this->contactB)
      ->addValue('relationship_type_id', $this->relationshipTypeId)
      ->addValue('start_date', date('Y-m-d', strtotime('-' . ($daysAgo + 100) . ' days')))
      ->addValue('end_date', date('Y-m-d', strtotime("-{$daysAgo} days")))
      ->execute()->first()['id'];
  }

  /**
   * Creates a CiviRules rule for this trigger and returns its id.
   *
   * @param string $name
   * @return int
   */
  private function createRule(string $name): int {
    $triggerTypeId = CiviRulesTrigger::get(FALSE)
      ->addWhere('class_name', '=', 'CRM_CivirulesCronTrigger_RelationshipEndDate')
      ->execute()->first()['id'];

    return CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => $name,
        'name' => $name,
        'trigger_id' => $triggerTypeId,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
  }

  /**
   * Inserts a civirule_rule_log row, logged $logDaysAgo days ago.
   *
   * Uses direct SQL because log_date defaults to CURRENT_TIMESTAMP and
   * several scenarios need backdated log entries.
   *
   * @param int $ruleId
   * @param int $contactId
   * @param int|null $relationshipId
   * @param int $logDaysAgo
   */
  private function createRuleLog(int $ruleId, int $contactId, ?int $relationshipId, int $logDaysAgo): void {
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule_log (rule_id, contact_id, entity_table, entity_id, log_date)
       VALUES (%1, %2, " . ($relationshipId ? "%3, %4" : "NULL, NULL") . ", DATE_SUB(NOW(), INTERVAL %5 DAY))",
      array_filter([
        1 => [$ruleId, 'Integer'],
        2 => [$contactId, 'Integer'],
        3 => $relationshipId ? ['civicrm_relationship', 'String'] : NULL,
        4 => $relationshipId ? [$relationshipId, 'Integer'] : NULL,
        5 => [$logDaysAgo, 'Integer'],
      ])
    );
  }

}
