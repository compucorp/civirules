<?php

namespace Civi\Civirules;

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\CiviRulesTrigger;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test the is_configured predicate.
 *
 * @group headless
 */
class ConfigurationStatusTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * A condition class with a configuration form.
   *
   * @var string
   */
  private const NEEDS_CONFIG = 'CRM_CivirulesConditions_Contact_InGroup';

  /**
   * A condition class whose getExtraDataInputUrl() returns FALSE.
   *
   * @var string
   */
  private const NO_CONFIG = 'CRM_CivirulesConditions_Contact_HasValidEmail';

  private int $ruleId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    // The no-config list is cached in Civi::cache('metadata'), which TransactionalInterface
    // does not roll back, so it can outlive the rows it was derived from.
    \CRM_Civirules_Utils_ConfigurationStatus::flushCache();
    $triggerId = CiviRulesTrigger::get(FALSE)->addSelect('id')->setLimit(1)->execute()->first()['id'];
    $this->ruleId = CiviRulesRule::create(FALSE)
      ->addValue('label', 'ConfigurationStatusTest')
      ->addValue('name', 'configurationstatustest')
      ->addValue('trigger_id', $triggerId)
      ->addValue('is_active', TRUE)
      ->execute()
      ->first()['id'];
  }

  private function conditionId(string $className): int {
    return CiviRulesCondition::get(FALSE)
      ->addSelect('id')
      ->addWhere('class_name', '=', $className)
      ->execute()
      ->first()['id'];
  }

  /**
   * @param string $className
   *   Condition class to attach to the rule.
   * @param mixed $params
   *   Omit entirely to leave condition_params NULL.
   *
   * @return int
   */
  private function addCondition(string $className, ...$params): int {
    $create = CiviRulesRuleCondition::create(FALSE)
      ->addValue('rule_id', $this->ruleId)
      ->addValue('condition_id', $this->conditionId($className));
    if ($params) {
      $create->addValue('condition_params', $params[0]);
    }
    return $create->execute()->first()['id'];
  }

  private function getIsConfigured(int $ruleConditionId): bool {
    return (bool) CiviRulesRuleCondition::get(FALSE)
      ->addSelect('is_configured')
      ->addWhere('id', '=', $ruleConditionId)
      ->execute()
      ->first()['is_configured'];
  }

  /**
   * The PHP predicate reads the raw column, as the engine has it.
   */
  private function getIsConfiguredViaPhp(int $ruleConditionId): bool {
    $row = \CRM_Core_DAO::executeQuery(
      'SELECT condition_params, condition_id FROM civirule_rule_condition WHERE id = %1',
      [1 => [$ruleConditionId, 'Integer']]
    );
    $row->fetch();
    return \CRM_Civirules_Utils_ConfigurationStatus::isRuleConditionConfigured([
      'condition_params' => $row->condition_params,
      'condition_id' => $row->condition_id,
    ]);
  }

  public function testNeverSavedConditionIsNotConfigured(): void {
    $id = $this->addCondition(self::NEEDS_CONFIG);
    $this->assertFalse($this->getIsConfigured($id));
    $this->assertFalse($this->getIsConfiguredViaPhp($id));
  }

  /**
   * Every configuration form stores serialize($data), so a form submitted with
   * nothing filled in stores 'a:0:{}'. That is a deliberate save and must not be
   * mistaken for an abandoned one - this is why the test is IS NOT NULL, not !empty.
   */
  public function testConditionSavedWithNoValuesIsConfigured(): void {
    $id = $this->addCondition(self::NEEDS_CONFIG, []);
    $this->assertSame('a:0:{}', \CRM_Core_DAO::singleValueQuery(
      'SELECT condition_params FROM civirule_rule_condition WHERE id = %1',
      [1 => [$id, 'Integer']]
    ));
    $this->assertTrue($this->getIsConfigured($id));
    $this->assertTrue($this->getIsConfiguredViaPhp($id));
  }

  public function testFullyConfiguredConditionIsConfigured(): void {
    $id = $this->addCondition(self::NEEDS_CONFIG, ['operator' => 'in', 'group_ids' => [1]]);
    $this->assertTrue($this->getIsConfigured($id));
    $this->assertTrue($this->getIsConfiguredViaPhp($id));
  }

  public function testConditionNeedingNoConfigurationIsConfigured(): void {
    $id = $this->addCondition(self::NO_CONFIG);
    $this->assertTrue($this->getIsConfigured($id));
    $this->assertTrue($this->getIsConfiguredViaPhp($id));
  }

  /**
   * The status check and any admin drill-down filter on this field, which only
   * works because it is rendered into SQL rather than computed after the fact.
   */
  public function testIsConfiguredIsFilterable(): void {
    $unconfigured = $this->addCondition(self::NEEDS_CONFIG);
    $this->addCondition(self::NEEDS_CONFIG, ['operator' => 'in', 'group_ids' => [1]]);
    $this->addCondition(self::NO_CONFIG);

    $found = CiviRulesRuleCondition::get(FALSE)
      ->addSelect('id')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->addWhere('is_configured', '=', FALSE)
      ->execute()
      ->column('id');

    $this->assertSame([$unconfigured], $found);
  }

  public function testUnconfiguredActionIsDetected(): void {
    $needsConfig = CiviRulesAction::get(FALSE)->addSelect('id')
      ->addWhere('class_name', '=', 'CRM_CivirulesActions_Tag_ContactTagAdd')->execute()->first()['id'];
    $noConfig = CiviRulesAction::get(FALSE)->addSelect('id')
      ->addWhere('class_name', '=', 'CRM_CivirulesActions_Log')->execute()->first()['id'];

    $unconfigured = CiviRulesRuleAction::create(FALSE)
      ->addValue('rule_id', $this->ruleId)->addValue('action_id', $needsConfig)->execute()->first()['id'];
    $configless = CiviRulesRuleAction::create(FALSE)
      ->addValue('rule_id', $this->ruleId)->addValue('action_id', $noConfig)->execute()->first()['id'];

    $byId = (array) CiviRulesRuleAction::get(FALSE)
      ->addSelect('id', 'is_configured')
      ->addWhere('rule_id', '=', $this->ruleId)
      ->execute()
      ->indexBy('id');

    $this->assertFalse((bool) $byId[$unconfigured]['is_configured']);
    $this->assertTrue((bool) $byId[$configless]['is_configured']);
  }

  /**
   * The cached "needs no configuration" list must agree with the classes themselves,
   * so that adding or removing a configuration form does not silently mis-flag rows.
   */
  public function testCachedNoConfigListMatchesTheClasses(): void {
    \CRM_Civirules_Utils_ConfigurationStatus::flushCache();
    $cached = \CRM_Civirules_Utils_ConfigurationStatus::getConditionIdsNotRequiringConfiguration();

    $expected = [];
    foreach (CiviRulesCondition::get(FALSE)->addSelect('id', 'class_name')->execute() as $condition) {
      if (empty($condition['class_name']) || !class_exists($condition['class_name'])) {
        continue;
      }
      $object = new $condition['class_name']();
      if ($object instanceof \CRM_Civirules_Condition && $object->getExtraDataInputUrl(0) === FALSE) {
        $expected[] = (int) $condition['id'];
      }
    }

    sort($cached);
    sort($expected);
    $this->assertSame($expected, $cached);
    $this->assertNotEmpty($expected, 'Expected at least one condition that needs no configuration');
  }

  /**
   * The configuration importer used to store '' for "no parameters", which is
   * indistinguishable from a configured-but-empty row under an IS NOT NULL test.
   */
  public function testUpgradeNormalisesEmptyStringParamsToNull(): void {
    $emptyString = $this->addCondition(self::NEEDS_CONFIG, []);
    $savedEmpty = $this->addCondition(self::NEEDS_CONFIG, []);
    \CRM_Core_DAO::executeQuery(
      "UPDATE civirule_rule_condition SET condition_params = '' WHERE id = %1",
      [1 => [$emptyString, 'Integer']]
    );
    $this->assertTrue($this->getIsConfigured($emptyString), 'Precondition: empty string reads as configured');

    $ctx = new \CRM_Queue_TaskContext();
    $ctx->log = \Civi::log();
    \CRM_Civirules_Upgrader::_queueAdapter($ctx, 'org.civicoop.civirules', 'upgrade_3009');

    $this->assertNull(\CRM_Core_DAO::singleValueQuery(
      'SELECT condition_params FROM civirule_rule_condition WHERE id = %1',
      [1 => [$emptyString, 'Integer']]
    ));
    $this->assertFalse($this->getIsConfigured($emptyString));

    // A genuinely saved empty form is left alone.
    $this->assertSame('a:0:{}', \CRM_Core_DAO::singleValueQuery(
      'SELECT condition_params FROM civirule_rule_condition WHERE id = %1',
      [1 => [$savedEmpty, 'Integer']]
    ));
    $this->assertTrue($this->getIsConfigured($savedEmpty));
  }

  public function testImportOfEmptyParametersYieldsNullNotEmptyString(): void {
    $condition = new \CRM_CivirulesConditions_Contact_HasValidEmail();
    $this->assertNull($condition->importConditionParameters(NULL));
    $this->assertNull($condition->importConditionParameters([]));

    $action = new \CRM_CivirulesActions_Log();
    $this->assertNull($action->importActionParameters(NULL));
    $this->assertNull($action->importActionParameters([]));
  }

}
