<?php
declare(strict_types = 1);

namespace Civi\Civirules;

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\EntityTag;
use Civi\Api4\OptionValue;
use Civi\Api4\Tag;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test CRM_Civirules_Upgrader::upgrade_3007(), which migrates the legacy
 * option-value-based CiviRules rule tags (civirule_rule_tag) to standard
 * CiviCRM Tags (civicrm_tag / civicrm_entity_tag).
 *
 * @group headless
 */
class CiviRulesTagsUpgradeTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  private int $ruleId;

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();
    // Looked up by name rather than assumed to be 1 - sql/triggers.json seeding
    // order determines the actual id, and hardcoding 1 makes this fail with an
    // FK constraint violation on civirule_rule.trigger_id if that ever doesn't hold.
    $triggerId = CiviRulesTrigger::get(FALSE)
      ->addWhere('name', '=', 'new_activity')
      ->execute()->first()['id'];
    $this->ruleId = CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'PHPUnit Upgrade Test Rule',
        'name' => 'phpunit_upgrade_test_rule',
        'trigger_id' => $triggerId,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
  }

  public function tearDown(): void {
    parent::tearDown();
  }

  /**
   * Seed a legacy-format rule tag: an OptionValue in group `civirule_rule_tag`
   * (whose `value` column - NOT `id` - is what civirule_rule_tag.rule_tag_id
   * stores) plus a civirule_rule_tag join row linking it to $this->ruleId.
   */
  private function seedLegacyRuleTag(int $optionValueValue, string $name, string $label): void {
    OptionValue::create(FALSE)
      ->addValue('option_group_id:name', 'civirule_rule_tag')
      ->addValue('value', $optionValueValue)
      ->addValue('name', $name)
      ->addValue('label', $label)
      ->execute();
    \CRM_Core_DAO::executeQuery(
      'INSERT INTO civirule_rule_tag (rule_id, rule_tag_id) VALUES (%1, %2)',
      [1 => [$this->ruleId, 'Integer'], 2 => [$optionValueValue, 'Integer']]
    );
  }

  private function runUpgradeStep(): void {
    $ctx = new \CRM_Queue_TaskContext();
    $ctx->log = \Civi::log();
    \CRM_Civirules_Upgrader::_queueAdapter($ctx, 'org.civicoop.civirules', 'upgrade_3007');
  }

  private function getEntityTagsForRule(): array {
    return EntityTag::get(FALSE)
      ->addWhere('entity_table', '=', 'civirule_rule')
      ->addWhere('entity_id', '=', $this->ruleId)
      ->execute()
      ->column('tag_id');
  }

  public function testMigratesLegacyTagToRealTagAndEntityTag(): void {
    $this->seedLegacyRuleTag(101, 'legacy_tag_a', 'Legacy Tag A');

    $this->runUpgradeStep();

    $newTag = Tag::get(FALSE)
      ->addWhere('name', '=', 'legacy_tag_a')
      ->execute()->first();
    $this->assertNotEmpty($newTag);
    $this->assertEquals('Legacy Tag A', $newTag['label']);
    $this->assertContains('civirule_rule', $newTag['used_for']);

    $this->assertEquals([$newTag['id']], $this->getEntityTagsForRule());
  }

  public function testRerunningTheUpgradeStepIsIdempotent(): void {
    $this->seedLegacyRuleTag(102, 'legacy_tag_b', 'Legacy Tag B');

    $this->runUpgradeStep();
    $firstRunTags = $this->getEntityTagsForRule();
    $firstRunTagCount = Tag::get(FALSE)->addWhere('name', '=', 'legacy_tag_b')->execute()->count();

    $this->runUpgradeStep();
    $secondRunTags = $this->getEntityTagsForRule();
    $secondRunTagCount = Tag::get(FALSE)->addWhere('name', '=', 'legacy_tag_b')->execute()->count();

    $this->assertEquals($firstRunTags, $secondRunTags);
    $this->assertEquals(1, $firstRunTagCount);
    $this->assertEquals(1, $secondRunTagCount);
  }

  public function testReusesAnExistingTagWithTheSameName(): void {
    $preExistingTag = Tag::create(FALSE)
      ->setValues(['name' => 'legacy_tag_c', 'label' => 'Pre-existing Tag C', 'used_for' => 'civicrm_contact'])
      ->execute()->first();
    $this->seedLegacyRuleTag(103, 'legacy_tag_c', 'Legacy Tag C');

    $this->runUpgradeStep();

    $matchingTags = Tag::get(FALSE)->addWhere('name', '=', 'legacy_tag_c')->execute();
    $this->assertCount(1, $matchingTags, 'Should reuse the existing tag rather than creating a duplicate');

    $reusedTag = $matchingTags->first();
    $this->assertEquals($preExistingTag['id'], $reusedTag['id']);
    // Original used_for is preserved, civirule_rule is appended.
    $this->assertContains('civicrm_contact', $reusedTag['used_for']);
    $this->assertContains('civirule_rule', $reusedTag['used_for']);

    $this->assertEquals([$preExistingTag['id']], $this->getEntityTagsForRule());
  }

  public function testDoesNothingWhenLegacyTableHasNoRows(): void {
    // No legacy rows seeded for this rule - upgrade step should be a no-op for it.
    $this->runUpgradeStep();

    $this->assertEquals([], $this->getEntityTagsForRule());
  }

}
