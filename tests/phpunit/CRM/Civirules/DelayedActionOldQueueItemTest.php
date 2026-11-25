<?php

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Delayed actions queued before CRM_Civirules_Trigger had typed properties
 * must still run, and a queue item that cannot be loaded must not stop the
 * other delayed actions.
 *
 * @group headless
 */
class CRM_Civirules_DelayedActionOldQueueItemTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $contactId;

  private int $tagId;

  private int $ruleId;

  private array $ruleAction;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    CRM_Core_DAO::executeQuery('DELETE FROM civicrm_queue_item WHERE queue_name = %1', [1 => [CRM_Civirules_Engine::QUEUE_NAME, 'String']]);
    $this->contactId = \Civi\Api4\Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'OldQueueItem')
      ->addValue('last_name', 'PHPUnit')
      ->execute()->first()['id'];
    $this->tagId = \Civi\Api4\Tag::create(FALSE)
      ->addValue('name', 'PHPUnit Old Queue Item')
      ->addValue('used_for', ['civicrm_contact'])
      ->execute()->first()['id'];
    $this->ruleId = CiviRulesRule::create(FALSE)
      ->addValue('name', 'phpunit_old_queue_item')
      ->addValue('label', 'PHPUnit Old Queue Item')
      ->addValue('trigger_id', $this->getTriggerId())
      ->addValue('is_active', TRUE)
      ->execute()->first()['id'];
    $delay = new CRM_Civirules_Delay_XMinutes();
    $delay->setValues(['xminutes_minuteOffset' => 60], '', new CRM_Civirules_BAO_CiviRulesRule());
    $ruleActionId = CiviRulesRuleAction::create(FALSE)
      ->addValue('rule_id', $this->ruleId)
      ->addValue('action_id', CiviRulesAction::get(FALSE)->addWhere('name', '=', 'contact_tag_add')->execute()->first()['id'])
      ->addValue('is_active', TRUE)
      ->execute()->first()['id'];
    // Stored as the rule action form stores them.
    CRM_Core_DAO::executeQuery('UPDATE civirule_rule_action SET action_params = %1, delay = %2 WHERE id = %3', [
      1 => [serialize(['tag_id' => [$this->tagId]]), 'String'],
      2 => [serialize($delay), 'String'],
      3 => [$ruleActionId, 'Integer'],
    ]);
    $this->ruleAction = CiviRulesRuleAction::get(FALSE)
      ->addWhere('id', '=', $ruleActionId)
      ->execute()->first();
  }

  private function getTriggerId(): int {
    return CiviRulesTrigger::get(FALSE)
      ->addWhere('name', '=', 'new_individual')
      ->execute()->first()['id'];
  }

  /**
   * Queue the delayed action the way a triggered rule does.
   */
  private function queueDelayedAction(): int {
    $trigger = CRM_Civirules_BAO_CiviRulesTrigger::getTriggerObjectByTriggerId($this->getTriggerId());
    $trigger->setRuleId($this->ruleId);
    $trigger->setTriggerParams(serialize([]));
    $triggerData = new CRM_Civirules_TriggerData_Post('Contact', $this->contactId, ['id' => $this->contactId]);
    $triggerData->setContactId($this->contactId);
    $triggerData->setTrigger($trigger);
    CRM_Civirules_Engine::executeAction($triggerData, $this->ruleAction);
    return (int) CRM_Core_DAO::singleValueQuery('SELECT MAX(id) FROM civicrm_queue_item WHERE queue_name = %1', [1 => [CRM_Civirules_Engine::QUEUE_NAME, 'String']]);
  }

  private function makeDue(int $queueItemId): void {
    CRM_Core_DAO::executeQuery('UPDATE civicrm_queue_item SET release_time = %1 WHERE id = %2', [
      1 => [date('YmdHis', strtotime('-1 minute')), 'String'],
      2 => [$queueItemId, 'Integer'],
    ]);
  }

  private function getQueueItemData(int $queueItemId): string {
    return CRM_Core_DAO::singleValueQuery('SELECT data FROM civicrm_queue_item WHERE id = %1', [1 => [$queueItemId, 'Integer']]);
  }

  private function setQueueItemData(int $queueItemId, string $data): void {
    CRM_Core_DAO::executeQuery('UPDATE civicrm_queue_item SET data = %1 WHERE id = %2', [
      1 => [$data, 'String'],
      2 => [$queueItemId, 'Integer'],
    ]);
  }

  private function contactHasTag(): bool {
    return (bool) \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civicrm_contact')
      ->addWhere('entity_id', '=', $this->contactId)
      ->addWhere('tag_id', '=', $this->tagId)
      ->execute()->count();
  }

  /**
   * Rewrite the queued trigger to hold the given property values, encoded as
   * serialize() writes them, the way CiviRules stored them before the
   * properties were typed.
   */
  private function toOldFormat(string $data, array $properties): string {
    $triggerClass = get_class(CRM_Civirules_BAO_CiviRulesTrigger::getTriggerObjectByTriggerId($this->getTriggerId()));
    $header = '/O:' . strlen($triggerClass) . ':"' . preg_quote($triggerClass, '/') . '":(\d+):\{/';
    $this->assertMatchesRegularExpression($header, $data);
    foreach ($properties as $name => $encodedValue) {
      $key = 's:' . (strlen($name) + 3) . ':"' . "\0*\0" . $name . '";';
      $simpleValue = '(?:N;|b:[01];|i:-?\d+;|s:\d+:"[^"]*";|a:0:\{\})';
      $existing = '/' . preg_quote($key, '/') . $simpleValue . '/';
      if (preg_match($existing, $data)) {
        $data = preg_replace($existing, str_replace(['\\', '$'], ['\\\\', '\\$'], $key . $encodedValue), $data, 1);
      }
      else {
        $data = preg_replace_callback($header, fn($m) => str_replace(':' . $m[1] . ':{', ':' . ($m[1] + 1) . ':{', $m[0]) . $key . $encodedValue, $data, 1);
      }
    }
    return $data;
  }

  /**
   * The three shapes found in queue items left by CiviRules 3.9 and 3.17.
   */
  public function oldFormats(): array {
    return [
      'FALSE triggerParams, NULL ruleTitle' => [
        [
          'triggerParams' => 'b:0;',
          'ruleTitle' => 'N;',
          'ruleDebugEnabled' => 's:1:"0";',
        ],
      ],
      'NULL ruleTitle' => [
        [
          'ruleTitle' => 'N;',
          'ruleDebugEnabled' => 's:1:"0";',
        ],
      ],
      'string ruleId and triggerId, NULL triggerParams' => [
        [
          'ruleId' => 'RULE_ID',
          'triggerId' => 'TRIGGER_ID',
          'triggerParams' => 'N;',
          'ruleTitle' => 'N;',
          'ruleDebugEnabled' => 's:1:"1";',
        ],
      ],
    ];
  }

  /**
   * @dataProvider oldFormats
   */
  public function testOldFormatDelayedActionRuns(array $properties): void {
    $queueItemId = $this->queueDelayedAction();
    foreach ($properties as $name => $value) {
      if ($value === 'RULE_ID' || $value === 'TRIGGER_ID') {
        $id = (string) ($value === 'RULE_ID' ? $this->ruleId : $this->getTriggerId());
        $properties[$name] = 's:' . strlen($id) . ':"' . $id . '";';
      }
    }
    $this->setQueueItemData($queueItemId, $this->toOldFormat($this->getQueueItemData($queueItemId), $properties));
    $this->makeDue($queueItemId);

    CRM_Civirules_Engine::processDelayedActions();

    $this->assertTrue($this->contactHasTag());
    $this->assertSame(0, (int) CRM_Core_DAO::singleValueQuery('SELECT COUNT(*) FROM civicrm_queue_item WHERE id = %1', [1 => [$queueItemId, 'Integer']]));
  }

  public function testOldFormatTriggerKeepsItsValues(): void {
    $queueItemId = $this->queueDelayedAction();
    $id = (string) $this->ruleId;
    $data = $this->toOldFormat($this->getQueueItemData($queueItemId), [
      'ruleId' => 's:' . strlen($id) . ':"' . $id . '";',
      'triggerParams' => 'b:0;',
      'ruleTitle' => 'N;',
      'ruleDebugEnabled' => 's:1:"1";',
    ]);

    $trigger = unserialize($data)->arguments[0]->getTriggerData()->getTrigger();

    $this->assertSame($this->ruleId, $trigger->getRuleId());
    $this->assertSame([], $trigger->getTriggerParams());
    $this->assertSame('PHPUnit Old Queue Item', $trigger->getRuleTitle());
    $this->assertTrue($trigger->getRuleDebugEnabled());
  }

  public function testUnloadableQueueItemDoesNotStopOtherDelayedActions(): void {
    $unloadableId = $this->queueDelayedAction();
    // An abstract class cannot be instantiated, so unserialize() throws.
    $this->setQueueItemData($unloadableId, 'O:21:"CRM_Civirules_Trigger":0:{}');
    $this->makeDue($unloadableId);
    $queueItemId = $this->queueDelayedAction();
    $this->makeDue($queueItemId);

    CRM_Civirules_Engine::processDelayedActions();

    $this->assertTrue($this->contactHasTag());
    $releaseTime = CRM_Core_DAO::singleValueQuery('SELECT release_time FROM civicrm_queue_item WHERE id = %1', [1 => [$unloadableId, 'Integer']]);
    $this->assertNotNull($releaseTime, 'The unloadable item is kept');
    $this->assertGreaterThan(time(), strtotime($releaseTime), 'The unloadable item is retried later');
  }

  public function testNewFormatDelayedActionRuns(): void {
    $queueItemId = $this->queueDelayedAction();
    $this->makeDue($queueItemId);

    CRM_Civirules_Engine::processDelayedActions();

    $this->assertTrue($this->contactHasTag());
  }

}
