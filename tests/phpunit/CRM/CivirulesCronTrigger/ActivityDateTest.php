<?php

use Civi\Api4\Activity;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Contact;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests the trigger_from_date floor on the "Activity date reached" cron
 * trigger (CRM_CivirulesCronTrigger_ActivityDate), which previously had no
 * lower bound at all and acted on every matching activity in the database.
 *
 * The trigger's protected getNextEntityTriggerData() is exercised directly via
 * reflection so only the trigger's own entity selection is under test.
 *
 * Fixtures are spaced in days: civirule_rule.created_date is a timestamp
 * rendered in the MySQL session timezone while civicrm_activity is a datetime
 * in CiviCRM's, so hour-scale margins are not reliable.
 *
 * @group headless
 */
class CRM_CivirulesCronTrigger_ActivityDateTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $ruleId;

  private int $activityTypeId;

  private int $activityStatusId;

  private int $sourceContactId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    $this->ruleId = $this->createRule('phpunit_activity_date_rule', 180);
    $this->activityTypeId = (int) CRM_Core_PseudoConstant::getKey('CRM_Activity_DAO_Activity', 'activity_type_id', 'Meeting');
    $this->activityStatusId = (int) CRM_Core_PseudoConstant::getKey('CRM_Activity_DAO_Activity', 'status_id', 'Completed');
    $this->sourceContactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Source')
      ->addValue('last_name', 'ActivityDateTest')
      ->execute()->first()['id'];
  }

  /**
   * With no trigger_from_date the rule's creation date is the floor. Before
   * this setting existed the trigger had no floor and both activities fired.
   */
  public function testFallsBackToRuleCreationDate(): void {
    $after = $this->createActivity(90);
    $this->createActivity(270);

    $this->assertEquals([$after], $this->getTriggeredActivityIds([]));
  }

  /**
   * A rule created months ago and only now switched on must not act on the
   * intervening activities.
   */
  public function testTriggerFromDateSuppressesBacklog(): void {
    $activityId = $this->createActivity(90);

    $this->assertEquals([$activityId], $this->getTriggeredActivityIds([]),
      'The creation date floor lets this activity through');
    $this->assertEquals([], $this->getTriggeredActivityIds(['trigger_from_date' => date('Y-m-d H:i:s')]),
      'A trigger from date of now must suppress the backlog');
  }

  /**
   * The floor is a lower bound, not a switch: activities dated after it still
   * fire.
   */
  public function testActivitiesAfterTriggerFromDateStillFire(): void {
    $after = $this->createActivity(5);
    $this->createActivity(20);

    $ids = $this->getTriggeredActivityIds(['trigger_from_date' => date('Y-m-d H:i:s', strtotime('-10 days'))]);

    $this->assertEquals([$after], $ids);
  }

  /**
   * Runs the trigger and collects the activity ids it yields.
   *
   * @param array $triggerParams
   * @return array
   */
  private function getTriggeredActivityIds(array $triggerParams): array {
    $trigger = new CRM_CivirulesCronTrigger_ActivityDate();
    $trigger->setRuleId($this->ruleId);
    $trigger->setTriggerParams(serialize($triggerParams + [
      'activity_type_id' => $this->activityTypeId,
      'activity_status_id' => $this->activityStatusId,
    ]));

    $method = new ReflectionMethod(CRM_CivirulesCronTrigger_ActivityDate::class, 'getNextEntityTriggerData');

    $ids = [];
    while ($triggerData = $method->invoke($trigger)) {
      $ids[] = (int) $triggerData->getEntityData('Activity')['id'];
    }
    sort($ids);
    return array_values(array_unique($ids));
  }

  /**
   * @param int $daysAgo
   * @return int
   */
  private function createActivity(int $daysAgo): int {
    return Activity::create(FALSE)
      ->addValue('activity_type_id', $this->activityTypeId)
      ->addValue('status_id', $this->activityStatusId)
      ->addValue('source_contact_id', $this->sourceContactId)
      ->addValue('subject', 'ActivityDateTest ' . $daysAgo)
      ->addValue('activity_date_time', date('Y-m-d H:i:s', strtotime("-{$daysAgo} days")))
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
      ->addWhere('class_name', '=', 'CRM_CivirulesCronTrigger_ActivityDate')
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
