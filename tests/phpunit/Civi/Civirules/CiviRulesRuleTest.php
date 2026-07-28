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

  /**
   * ID of the "Activity is added" (new_activity) trigger. Looked up by name
   * rather than assumed to be 1 - sql/triggers.json seeding order determines
   * the actual id, and hardcoding 1 makes tests fail with an FK constraint
   * violation on civirule_rule.trigger_id if that assumption ever doesn't hold.
   *
   * @var int
   */
  private $triggerId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    $this->triggerId = \Civi\Api4\CiviRulesTrigger::get(FALSE)
      ->addWhere('name', '=', 'new_activity')
      ->execute()->first()['id'];

    // Create a standard CiviCRM Tag usable on CiviRules rules
    $this->tagId = \Civi\Api4\Tag::create(FALSE)
      ->setValues([
        'label' => 'Test PHPUnit Tag',
        'name' => 'test_phpunit_tag',
        'used_for' => 'civirule_rule',
        'color' => '#ff6600',
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
        'trigger_id' => $this->triggerId,
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

    // 2. Fetch the rule via API and test virtual fields: trigger_id:label, tag_id, tag_id:label, tag_id:color, last_run_date
    $fetched = \Civi\Api4\CiviRulesRule::get(FALSE)
      ->addSelect('id', 'label', 'trigger_id:label', 'tag_id', 'tag_id:label', 'tag_id:color', 'last_run_date')
      ->addWhere('id', '=', $rule['id'])
      ->execute()->first();

    $this->assertEquals('Activity is added', $fetched['trigger_id:label']);
    $this->assertEquals([$this->tagId], $fetched['tag_id']);
    $this->assertEquals(['Test PHPUnit Tag'], $fetched['tag_id:label']);
    // The color suffix is what SearchKit's `colors` column option (see
    // managed/SavedSearch_CiviRules.mgd.php) relies on to render tag pills.
    $this->assertEquals(['#ff6600'], $fetched['tag_id:color']);
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
        'trigger_id' => $this->triggerId,
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

  /**
   * The APIv4 CiviRulesRule.copy action (used by the SearchKit "Clone" task)
   * should clone a rule the same way the v3 clone API does: disabled by
   * default, tags copied.
   */
  public function testApi4CopyActionClonesRuleIncludingTags(): void {
    $rule = \Civi\Api4\CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'PHPUnit Api4 Copy Source Rule',
        'name' => 'phpunit_api4_copy_source_rule',
        'trigger_id' => $this->triggerId,
        'is_active' => TRUE,
        'tag_id' => [$this->tagId],
      ])
      ->execute()->first();

    $copyResult = \Civi\Api4\CiviRulesRule::copy(FALSE)
      ->addWhere('id', '=', $rule['id'])
      ->execute();

    $this->assertCount(1, $copyResult);
    $cloneId = $copyResult->first()['clone_id'];
    $this->assertNotEquals($rule['id'], $cloneId);

    $clonedRule = \Civi\Api4\CiviRulesRule::get(FALSE)
      ->addWhere('id', '=', $cloneId)
      ->execute()->first();
    $this->assertEquals('Clone of PHPUnit Api4 Copy Source Rule', $clonedRule['label']);
    $this->assertFalse((bool) $clonedRule['is_active']);

    $cloneTags = \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civirule_rule')
      ->addWhere('entity_id', '=', $cloneId)
      ->execute();
    $this->assertCount(1, $cloneTags);
    $this->assertEquals($this->tagId, $cloneTags->first()['tag_id']);
  }

  /**
   * The copy action is a batch action (per AbstractBatchAction) - a `where`
   * clause matching several rules should clone all of them in one call, as
   * happens when cloning multiple selected rows from the SearchKit list.
   */
  public function testApi4CopyActionClonesMultipleMatchedRules(): void {
    $ruleA = \Civi\Api4\CiviRulesRule::create(FALSE)
      ->setValues(['label' => 'Batch Copy A', 'name' => 'batch_copy_a', 'trigger_id' => $this->triggerId, 'is_active' => TRUE])
      ->execute()->first();
    $ruleB = \Civi\Api4\CiviRulesRule::create(FALSE)
      ->setValues(['label' => 'Batch Copy B', 'name' => 'batch_copy_b', 'trigger_id' => $this->triggerId, 'is_active' => TRUE])
      ->execute()->first();

    $copyResult = \Civi\Api4\CiviRulesRule::copy(FALSE)
      ->addWhere('id', 'IN', [$ruleA['id'], $ruleB['id']])
      ->execute();

    $this->assertCount(2, $copyResult);
    $sourceIds = $copyResult->column('id');
    $this->assertEqualsCanonicalizing([$ruleA['id'], $ruleB['id']], $sourceIds);
  }

  /**
   * Regression test: re-submitting a tag that's already attached to the rule
   * (e.g. a UI "remove then re-add" of the same tag before saving) must not
   * throw a duplicate-key DB error.
   *
   * self_hook_civicrm_post() replaces the rule's tags via EntityTag::replace(),
   * which requires setMatch() to correctly identify rows that already exist;
   * without it every submitted tag is (re-)inserted as if new.
   */
  public function testUpdateWithAlreadyAttachedTagDoesNotDuplicate(): void {
    $rule = \Civi\Api4\CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'PHPUnit Tag Resubmit Rule',
        'name' => 'phpunit_tag_resubmit_rule',
        'trigger_id' => $this->triggerId,
        'is_active' => TRUE,
        'tag_id' => [$this->tagId],
      ])
      ->execute()->first();

    // Re-submit the same, already-attached tag.
    \Civi\Api4\CiviRulesRule::update(FALSE)
      ->addWhere('id', '=', $rule['id'])
      ->setValues(['tag_id' => [$this->tagId]])
      ->execute();

    $savedTags = \Civi\Api4\EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civirule_rule')
      ->addWhere('entity_id', '=', $rule['id'])
      ->execute();
    $this->assertCount(1, $savedTags);
    $this->assertEquals($this->tagId, $savedTags->first()['tag_id']);
  }

}
