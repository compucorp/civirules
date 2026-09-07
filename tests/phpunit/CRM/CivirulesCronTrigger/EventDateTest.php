<?php

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Contact;
use Civi\Api4\Event;
use Civi\Api4\Participant;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests for the "Event Date reached" cron trigger
 * (CRM_CivirulesCronTrigger_EventDate), covering the trigger_from_date floor
 * and its fallback to the rule's creation date.
 *
 * The trigger's protected getNextEntityTriggerData() is exercised directly via
 * reflection so only the trigger's own entity selection is under test, without
 * involving the rule engine, conditions or actions.
 *
 * Fixtures are spaced in days: civirule_rule.created_date is a timestamp
 * rendered in the MySQL session timezone while civicrm_event.start_date is a
 * datetime in CiviCRM's, so hour-scale margins are not reliable.
 *
 * @group headless
 */
class CRM_CivirulesCronTrigger_EventDateTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $ruleId;

  private int $eventTypeId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    // Every scenario contrasts a rule created a while ago with a later cutoff.
    $this->ruleId = $this->createRule('phpunit_event_date_rule', 180);
    $this->eventTypeId = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT value FROM civicrm_option_value v
       INNER JOIN civicrm_option_group g ON g.id = v.option_group_id AND g.name = 'event_type'
       ORDER BY v.value LIMIT 1"
    );
  }

  /**
   * With no trigger_from_date the rule's creation date is the floor, which is
   * the behaviour that predates the setting.
   */
  public function testFallsBackToRuleCreationDate(): void {
    $after = $this->createParticipant($this->createEvent(90));
    $this->createParticipant($this->createEvent(270));

    $this->assertEquals([$after], $this->getTriggeredParticipantIds([]));
  }

  /**
   * The regression this setting exists to prevent: a rule created months ago
   * and only now switched on must not act on the intervening events.
   */
  public function testTriggerFromDateSuppressesBacklog(): void {
    $participantId = $this->createParticipant($this->createEvent(90));

    $this->assertEquals([$participantId], $this->getTriggeredParticipantIds([]),
      'Without a trigger from date the backlog fires');
    $this->assertEquals([], $this->getTriggeredParticipantIds(['trigger_from_date' => date('Y-m-d H:i:s')]),
      'A trigger from date of now must suppress the backlog');
  }

  /**
   * Events dated after the cutoff still fire; the floor is a lower bound, not
   * a switch.
   */
  public function testEventsAfterTriggerFromDateStillFire(): void {
    $after = $this->createParticipant($this->createEvent(5));
    $this->createParticipant($this->createEvent(20));

    $ids = $this->getTriggeredParticipantIds(['trigger_from_date' => date('Y-m-d H:i:s', strtotime('-10 days'))]);

    $this->assertEquals([$after], $ids);
  }

  /**
   * An event whose date has not yet arrived never fires, whatever the cutoff.
   */
  public function testFutureEventDoesNotFire(): void {
    $this->createParticipant($this->createEvent(-10));

    $this->assertEquals([], $this->getTriggeredParticipantIds(['trigger_from_date' => date('Y-m-d H:i:s', strtotime('-30 days'))]));
  }

  /**
   * A trigger from date earlier than the rule's creation date wins: it is an
   * override, not a second floor on top of created_date.
   */
  public function testTriggerFromDateEarlierThanCreationDateWins(): void {
    $ruleId = $this->createRule('phpunit_event_date_recent_rule', 30);
    $participantId = $this->createParticipant($this->createEvent(90));

    $this->assertEquals([], $this->getTriggeredParticipantIds([], $ruleId),
      'The event predates this rule, so the creation date floor excludes it');
    $this->assertEquals([$participantId], $this->getTriggeredParticipantIds(['trigger_from_date' => date('Y-m-d H:i:s', strtotime('-180 days'))], $ruleId),
      'An earlier trigger from date must let the event through');
  }

  /**
   * With an offset the floor moves with it, so an event already inside the
   * offset window fires immediately even though its own date is in the future.
   */
  public function testOffsetTriggerFiresForEventInsideTheOffsetWindow(): void {
    $upcoming = $this->createParticipant($this->createEvent(-3));
    $this->createParticipant($this->createEvent(30));

    $ids = $this->getTriggeredParticipantIds([
      'offset' => 7,
      'offset_type' => '-',
      'offset_unit' => 'DAY',
      'trigger_from_date' => date('Y-m-d H:i:s'),
    ]);

    $this->assertEquals([$upcoming], $ids);
  }

  /**
   * Moving the trigger from date backwards must not re-fire a participant the
   * rule has already acted on.
   */
  public function testRuleLogStillSuppressesRepeats(): void {
    $eventId = $this->createEvent(90);
    $participantId = $this->createParticipant($eventId);
    $contactId = (int) CRM_Core_DAO::singleValueQuery("SELECT contact_id FROM civicrm_participant WHERE id = %1", [
      1 => [$participantId, 'Integer'],
    ]);
    CRM_Core_DAO::executeQuery(
      "INSERT INTO civirule_rule_log (rule_id, contact_id, entity_table, entity_id) VALUES (%1, %2, 'civicrm_participant', %3)",
      [
        1 => [$this->ruleId, 'Integer'],
        2 => [$contactId, 'Integer'],
        3 => [$participantId, 'Integer'],
      ]
    );

    $this->assertEquals([], $this->getTriggeredParticipantIds(['trigger_from_date' => date('Y-m-d H:i:s', strtotime('-365 days'))]));
  }

  /**
   * The description names the configured cutoff, and must render on the rules
   * list too - where the trigger is hydrated without a rule id.
   */
  public function testTriggerDescriptionReportsTheCutoff(): void {
    $configured = '2026-01-15 09:00:00';

    $withDate = $this->buildTrigger(['trigger_from_date' => $configured], NULL);
    $this->assertStringContainsString(CRM_Utils_Date::customFormat($configured), $withDate->getTriggerDescription());

    $withoutDate = $this->buildTrigger([], NULL);
    $this->assertStringContainsString('before the date the rule was created', $withoutDate->getTriggerDescription());
  }

  /**
   * Runs the trigger and collects the participant ids it yields.
   *
   * @param array $triggerParams
   * @param int|null $ruleId
   * @return array
   */
  private function getTriggeredParticipantIds(array $triggerParams, ?int $ruleId = NULL): array {
    $trigger = $this->buildTrigger($triggerParams, $ruleId ?? $this->ruleId);

    $method = new ReflectionMethod(CRM_CivirulesCronTrigger_EventDate::class, 'getNextEntityTriggerData');
    $method->setAccessible(TRUE);

    $ids = [];
    while ($triggerData = $method->invoke($trigger)) {
      $ids[] = (int) $triggerData->getEntityData('Participant')['id'];
    }
    sort($ids);
    return $ids;
  }

  /**
   * @param array $triggerParams
   * @param int|null $ruleId
   * @return \CRM_CivirulesCronTrigger_EventDate
   */
  private function buildTrigger(array $triggerParams, ?int $ruleId): CRM_CivirulesCronTrigger_EventDate {
    $trigger = new CRM_CivirulesCronTrigger_EventDate();
    if ($ruleId !== NULL) {
      $trigger->setRuleId($ruleId);
    }
    $trigger->setTriggerParams(serialize($triggerParams + [
      'run_type' => 'participant',
      'date_field' => 'start_date',
    ]));
    return $trigger;
  }

  /**
   * Creates an active event whose start date is $daysAgo days in the past
   * (negative for the future).
   *
   * @param int $daysAgo
   * @return int
   */
  private function createEvent(int $daysAgo): int {
    return Event::create(FALSE)
      ->addValue('title', 'EventDateTest ' . $daysAgo)
      ->addValue('event_type_id', $this->eventTypeId)
      ->addValue('start_date', date('Y-m-d H:i:s', strtotime("-{$daysAgo} days")))
      ->addValue('is_active', TRUE)
      ->execute()->first()['id'];
  }

  /**
   * @param int $eventId
   * @return int
   */
  private function createParticipant(int $eventId): int {
    $contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Attendee' . $eventId)
      ->addValue('last_name', 'EventDateTest')
      ->execute()->first()['id'];

    return Participant::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('event_id', $eventId)
      ->execute()->first()['id'];
  }

  /**
   * Creates a CiviRules rule for this trigger, created $createdDaysAgo days
   * ago. created_date is readonly and defaults to CURRENT_TIMESTAMP, so it has
   * to be backdated with direct SQL.
   *
   * @param string $name
   * @param int $createdDaysAgo
   * @return int
   */
  private function createRule(string $name, int $createdDaysAgo): int {
    $triggerTypeId = CiviRulesTrigger::get(FALSE)
      ->addWhere('class_name', '=', 'CRM_CivirulesCronTrigger_EventDate')
      ->execute()->first()['id'];

    $ruleId = CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => $name,
        'name' => $name,
        'trigger_id' => $triggerTypeId,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];

    CRM_Core_DAO::executeQuery(
      "UPDATE civirule_rule SET created_date = DATE_SUB(NOW(), INTERVAL %2 DAY) WHERE id = %1",
      [
        1 => [$ruleId, 'Integer'],
        2 => [$createdDaysAgo, 'Integer'],
      ]
    );

    return $ruleId;
  }

}
