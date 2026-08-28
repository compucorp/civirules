<?php

namespace Civi\Civirules;

use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * The system status check that reports rules containing unconfigured items.
 *
 * @group headless
 */
class UnconfiguredStatusCheckTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    \CRM_Civirules_Utils_ConfigurationStatus::flushCache();
    // The check reports on every rule in the system, so it can only be asserted
    // against a known baseline. Rules created by other test classes in this run are
    // not always rolled back before this one starts.
    CiviRulesRule::delete(FALSE)->addWhere('id', '>', 0)->execute();
  }

  private function createRule(string $name, bool $isActive): int {
    return CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => $name,
        'name' => $name,
        'trigger_id' => CiviRulesTrigger::get(FALSE)->addSelect('id')->setLimit(1)->execute()->first()['id'],
        'is_active' => $isActive,
      ])
      ->execute()->first()['id'];
  }

  private function addCondition(int $ruleId, ?array $params): void {
    $values = [
      'rule_id' => $ruleId,
      'condition_id' => CiviRulesCondition::get(FALSE)->addWhere('name', '=', 'contact_in_group')->execute()->first()['id'],
      'is_active' => TRUE,
    ];
    if ($params !== NULL) {
      $values['condition_params'] = $params;
    }
    CiviRulesRuleCondition::create(FALSE)->setValues($values)->execute();
  }

  /**
   * @return \CRM_Utils_Check_Message[]
   */
  private function runCheck(): array {
    return \CRM_Civirules_Utils_StatusCheck::checkUnconfigured();
  }

  public function testNoMessageWhenEverythingIsConfigured(): void {
    $ruleId = $this->createRule('phpunit_status_ok', TRUE);
    $this->addCondition($ruleId, ['operator' => 'in', 'group_ids' => [1]]);

    $this->assertSame([], $this->runCheck());
  }

  public function testReportsRuleWithAnUnconfiguredCondition(): void {
    $ruleId = $this->createRule('phpunit_status_bad', TRUE);
    $this->addCondition($ruleId, NULL);

    $messages = $this->runCheck();

    $this->assertCount(1, $messages);
    $this->assertSame(\CRM_Civirules_Utils_StatusCheck::NAME, $messages[0]->getName());
    $this->assertStringContainsString('phpunit_status_bad', $messages[0]->getMessage());
  }

  /**
   * A disabled rule cannot misbehave, so it is worth reporting but not warning about.
   */
  public function testSeverityIsLowerWhenOnlyDisabledRulesAreAffected(): void {
    $activeRule = $this->createRule('phpunit_status_active', TRUE);
    $this->addCondition($activeRule, NULL);
    $this->assertSame(\Psr\Log\LogLevel::WARNING, $this->runCheck()[0]->getSeverity());

    \Civi\Api4\CiviRulesRule::update(FALSE)
      ->addWhere('id', '=', $activeRule)
      ->addValue('is_active', FALSE)
      ->execute();

    $this->assertSame(\Psr\Log\LogLevel::NOTICE, $this->runCheck()[0]->getSeverity());
  }

  public function testCheckIsRegisteredWithTheHook(): void {
    $ruleId = $this->createRule('phpunit_status_hook', TRUE);
    $this->addCondition($ruleId, NULL);

    $messages = [];
    civirules_civicrm_check($messages, [\CRM_Civirules_Utils_StatusCheck::NAME]);

    $this->assertCount(1, $messages);
  }

  public function testHookIgnoresRequestsForOtherChecks(): void {
    $ruleId = $this->createRule('phpunit_status_other', TRUE);
    $this->addCondition($ruleId, NULL);

    $messages = [];
    civirules_civicrm_check($messages, ['someOtherCheck']);

    $this->assertSame([], $messages);
  }

}
