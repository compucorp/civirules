<?php

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesTrigger;
use Civi\Api4\Contact;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test CRM_Civirules_Trigger_Post::post() when two different trigger
 * classes match the same objectName/op.
 *
 * post() caches the first trigger's data and hands it to every later
 * trigger for the same event. CRM_Civirules_Trigger_Post::triggerTrigger()
 * only builds its own data via getTriggerDataFromPost() when none is set
 * yet (!hasTriggerData()) - so an injected object from a different class
 * suppresses that class's own override, and whatever it attaches there is
 * silently lost. If the cache isn't scoped per class, that's exactly what
 * happens: PostCrossClassCacheTest_MarkerFixture's Marker entity never
 * gets attached when PlainFixture happened to run first and seed the
 * cache with data that has no Marker.
 *
 * Fixture classes (PostCrossClassCacheTestFixtures.php) are used here
 * rather than real triggers like CRM_CivirulesPostTrigger_Activity /
 * CaseActivity - civirules' one production pair affected by this - so
 * the test only needs a Contact, with no dependency on the Case entity
 * or the civi_case component.
 *
 * @group headless
 */
class CRM_Civirules_Trigger_PostCrossClassCacheTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp(): void {
    parent::setUp();

    // Required lazily, not at the top of this file: the fixtures extend
    // a CiviCRM class, and PHPUnit's suite loader parses test files
    // before CiviCRM has booted.
    require_once __DIR__ . '/PostCrossClassCacheTestFixtures.php';

    CRM_Civirules_Trigger_PostCrossClassCacheTest_PlainFixture::$seen = [];
    CRM_Civirules_Trigger_PostCrossClassCacheTest_MarkerFixture::$seen = [];
  }

  private function createRule(string $triggerClass): int {
    $triggerId = CiviRulesTrigger::create(FALSE)
      ->setValues([
        'name' => 'phpunit_' . $triggerClass,
        'label' => 'PHPUnit trigger for ' . $triggerClass,
        'object_name' => 'Contact',
        'op' => 'create',
        'class_name' => $triggerClass,
        'cron' => FALSE,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];

    return CiviRulesRule::create(FALSE)
      ->setValues([
        'label' => 'PHPUnit rule for ' . $triggerClass,
        'name' => 'phpunit_rule_' . $triggerClass,
        'trigger_id' => $triggerId,
        'is_active' => TRUE,
      ])
      ->execute()->first()['id'];
  }

  public function testMarkerTriggerGetsItsOwnTriggerDataRegardlessOfEvaluationOrder(): void {
    $this->createRule(CRM_Civirules_Trigger_PostCrossClassCacheTest_PlainFixture::class);
    $this->createRule(CRM_Civirules_Trigger_PostCrossClassCacheTest_MarkerFixture::class);

    $contactId = Contact::create(FALSE)
      ->addValue('contact_type', 'Individual')
      ->addValue('first_name', 'Post')
      ->addValue('last_name', 'PHPUnit')
      ->execute()->first()['id'];

    $objectRef = new CRM_Contact_DAO_Contact();
    $objectRef->id = $contactId;
    $objectRef->find(TRUE);

    CRM_Civirules_Trigger_Post::post('create', 'Contact', $contactId, $objectRef, NULL);

    $this->assertCount(1, CRM_Civirules_Trigger_PostCrossClassCacheTest_PlainFixture::$seen, 'The plain trigger should have fired exactly once.');
    $this->assertCount(1, CRM_Civirules_Trigger_PostCrossClassCacheTest_MarkerFixture::$seen, 'The marker trigger should have fired exactly once.');
    $this->assertSame(
      ['flag' => 1],
      CRM_Civirules_Trigger_PostCrossClassCacheTest_MarkerFixture::$seen[0],
      'The marker trigger must build its own trigger data via its getTriggerDataFromPost() override - not receive whatever a different trigger class cached for the same event - so its Marker entity is never lost.'
    );
  }

}
