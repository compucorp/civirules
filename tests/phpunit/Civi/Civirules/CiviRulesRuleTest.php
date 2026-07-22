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
  private $tagId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    // Create a standard CiviCRM Tag usable on CiviRules rules
    $this->tagId = \Civi\Api4\Tag::create(FALSE)
      ->setValues([
        'label' => 'Test PHPUnit Tag',
        'name' => 'test_phpunit_tag',
        'used_for' => 'civirule_rule',
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
        'tag_id' => [$this->tagId],
      ])
      ->execute()->first();

    $this->assertNotEmpty($rule['id']);
    $this->assertEquals('phpunit_test_rule', $rule['name']);

    // Verify tag is saved as a real EntityTag row
    $savedTags = \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civirule_rule')
      ->addWhere('entity_id', '=', $rule['id'])
      ->execute();
    $this->assertCount(1, $savedTags);
    $this->assertEquals($this->tagId, $savedTags->first()['tag_id']);

    // 2. Fetch the rule via API and test virtual fields: trigger_id:label, tag_id, tag_id:label, last_run_date
    $fetched = \Civi\Api4\CiviRulesRule::get(FALSE)
      ->addSelect('id', 'label', 'trigger_id:label', 'tag_id', 'tag_id:label', 'last_run_date')
      ->addWhere('id', '=', $rule['id'])
      ->execute()->first();

    $this->assertEquals('Activity is added', $fetched['trigger_id:label']);
    $this->assertEquals([$this->tagId], $fetched['tag_id']);
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

    $savedTagsAfterUpdate = \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civirule_rule')
      ->addWhere('entity_id', '=', $rule['id'])
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

  /**
   * Cloning a rule should also copy its tags (as real EntityTag rows).
   */
  public function testCloneRuleCopiesTags(): void {
    $rule = \Civi\Api4\CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'PHPUnit Clone Source Rule',
        'name' => 'phpunit_clone_source_rule',
        'trigger_id' => 1, /* Activity is added */
        'is_active' => TRUE,
        'tag_id' => [$this->tagId],
      ])
      ->execute()->first();

    $cloneResult = \civicrm_api3('CiviRulesRule', 'clone', ['id' => $rule['id']]);
    $cloneId = $cloneResult['values']['clone_id'];

    $this->assertNotEquals($rule['id'], $cloneId);

    $cloneTags = \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civirule_rule')
      ->addWhere('entity_id', '=', $cloneId)
      ->execute();
    $this->assertCount(1, $cloneTags);
    $this->assertEquals($this->tagId, $cloneTags->first()['tag_id']);
  }

}
