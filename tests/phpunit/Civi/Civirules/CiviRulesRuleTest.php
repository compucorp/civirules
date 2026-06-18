<?php
declare(strict_types = 1);

namespace Civi\Civirules;

use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test CiviRulesRule APIv4 CRUD and virtual fields.
 *
 * @group headless
 */
class CiviRulesRuleTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  /**
   * @var int
   */
  private $tagOptionValueId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    // Create option value for rule tags
    $this->tagOptionValueId = \Civi\Api4\OptionValue::create(FALSE)
      ->setValues([
        'option_group_id:name' => 'civirule_rule_tag',
        'label' => 'Test PHPUnit Tag',
        'value' => 10,
        'name' => 'test_phpunit_tag',
      ])
      ->execute()->first()['id'];
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  /**
   * Test APIv4 CRUD operations on CiviRulesRule, including custom Virtual Fields
   */
  public function testCiviRulesRuleCrud(): void {
    // 1. Create a CiviRulesRule with trigger and tag
    $rule = \Civi\Api4\CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'PHPUnit Test Rule',
        'name' => 'phpunit_test_rule',
        'trigger_id' => 1, /* Activity is added */
        'is_active' => TRUE,
        'tag_id' => [10],
      ])
      ->execute()->first();

    $this->assertNotEmpty($rule['id']);
    $this->assertEquals('phpunit_test_rule', $rule['name']);

    // Verify tag is saved in the join table
    $savedTags = \Civi\Api4\CiviRulesRuleTag::get(FALSE)
      ->addWhere('rule_id', '=', $rule['id'])
      ->execute();
    $this->assertCount(1, $savedTags);
    $this->assertEquals(10, $savedTags->first()['rule_tag_id']);

    // 2. Fetch the rule via API and test virtual fields: trigger_id:label, tag_id, tag_id:label, last_run_date
    $fetched = \Civi\Api4\CiviRulesRule::get(FALSE)
      ->addSelect('id', 'label', 'trigger_id:label', 'tag_id', 'tag_id:label', 'last_run_date')
      ->addWhere('id', '=', $rule['id'])
      ->execute()->first();

    $this->assertEquals('Activity is added', $fetched['trigger_id:label']);
    $this->assertEquals([10], $fetched['tag_id']);
    $this->assertEquals(['Test PHPUnit Tag'], $fetched['tag_id:label']);
    $this->assertNull($fetched['last_run_date']);

    // Create a log entry and check last_run_date
    $log = \Civi\Api4\CiviRulesRuleLog::create(FALSE)
      ->setValues([
        'rule_id' => $rule['id'],
        'contact_id' => 1,
        'log_date' => '2026-06-04 12:00:00',
      ])
      ->execute()->first();

    $fetchedWithLog = \Civi\Api4\CiviRulesRule::get(FALSE)
      ->addSelect('id', 'last_run_date')
      ->addWhere('id', '=', $rule['id'])
      ->execute()->first();

    $this->assertEquals('2026-06-04 12:00:00', $fetchedWithLog['last_run_date']);

    // 3. Update the rule's tags to empty list
    \Civi\Api4\CiviRulesRule::update(FALSE)
      ->addWhere('id', '=', $rule['id'])
      ->setValues(['tag_id' => []])
      ->execute();

    $savedTagsAfterUpdate = \Civi\Api4\CiviRulesRuleTag::get(FALSE)
      ->addWhere('rule_id', '=', $rule['id'])
      ->execute();
    $this->assertCount(0, $savedTagsAfterUpdate);

    // 4. Delete the rule
    \Civi\Api4\CiviRulesRule::delete(FALSE)
      ->addWhere('id', '=', $rule['id'])
      ->execute();

    $checkDeleted = \Civi\Api4\CiviRulesRule::get(FALSE)
      ->addWhere('id', '=', $rule['id'])
      ->execute();
    $this->assertCount(0, $checkDeleted);
  }

}
