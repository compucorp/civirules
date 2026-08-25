<?php

namespace Civi\Civirules;

use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesCondition;
use Civi\Test\CiviEnvBuilder;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;

/**
 * Every action/condition must be able to name its configuration form without a
 * saved row, so the form can be reached before the row is created.
 *
 * @group headless
 */
class ConfigFormRouteTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): CiviEnvBuilder {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * @return array [label, object, needsConfig]
   */
  private function allClasses(): array {
    $out = [];
    foreach (CiviRulesAction::get(FALSE)->addSelect('name', 'class_name')->execute() as $row) {
      if (empty($row['class_name']) || !class_exists($row['class_name'])) {
        continue;
      }
      $object = new $row['class_name']();
      if ($object instanceof \CRM_Civirules_Action) {
        $out[] = ['action ' . $row['name'], $object];
      }
    }
    foreach (CiviRulesCondition::get(FALSE)->addSelect('name', 'class_name')->execute() as $row) {
      if (empty($row['class_name']) || !class_exists($row['class_name'])) {
        continue;
      }
      $object = new $row['class_name']();
      if ($object instanceof \CRM_Civirules_Condition) {
        $out[] = ['condition ' . $row['name'], $object];
      }
    }
    return $out;
  }

  /**
   * getConfigFormPath() must agree with getExtraDataInputUrl() about whether
   * configuration is needed at all.
   */
  public function testEveryClassAgreesOnWhetherItNeedsConfiguration(): void {
    $checked = 0;
    foreach ($this->allClasses() as [$label, $object]) {
      $needsConfig = !empty($object->getExtraDataInputUrl(1));
      $path = $object->getConfigFormPath();
      $this->assertSame(
        $needsConfig,
        $path !== NULL,
        "{$label}: getConfigFormPath() " . var_export($path, TRUE) . " disagrees with getExtraDataInputUrl()"
      );
      $checked++;
    }
    $this->assertGreaterThan(100, $checked, 'Expected to check every shipped action and condition');
  }

  /**
   * A path that does not resolve to a menu route would 404 in the new flow.
   */
  public function testEveryConfigFormPathResolvesToARoutableForm(): void {
    foreach ($this->allClasses() as [$label, $object]) {
      $path = $object->getConfigFormPath();
      if ($path === NULL) {
        continue;
      }
      $item = \CRM_Core_Menu::get($path);
      $this->assertNotEmpty($item, "{$label}: no menu route for '{$path}'");
      $callback = $item['page_callback'] ?? NULL;
      $this->assertNotEmpty($callback, "{$label}: menu route '{$path}' has no page_callback");
      $this->assertTrue(class_exists($callback), "{$label}: page_callback {$callback} does not exist");
    }
  }

  /**
   * The extra parameters some forms need (tn / entity / condition_name) are
   * derived from the class, so they are available before any row exists.
   */
  public function testClassesWithHandBuiltUrlsDeclareTheirExtraParams(): void {
    $expected = [
      'CRM_CivirulesActions_Tag_ContactTagAdd' => ['tn' => 'civicrm_contact'],
      'CRM_CivirulesActions_Tag_ContactTagRemove' => ['tn' => 'civicrm_contact'],
      'CRM_CivirulesActions_Tag_ActivityTagAdd' => ['tn' => 'civicrm_activity'],
      'CRM_CivirulesActions_Tag_ActivityTagRemove' => ['tn' => 'civicrm_activity'],
      'CRM_CivirulesActions_Tag_CaseTagAdd' => ['tn' => 'civicrm_case'],
      'CRM_CivirulesActions_Tag_CaseTagRemove' => ['tn' => 'civicrm_case'],
      'CRM_CivirulesActions_Tag_FileTagAdd' => ['tn' => 'civicrm_file'],
      'CRM_CivirulesActions_Tag_FileTagRemove' => ['tn' => 'civicrm_file'],
      'CRM_CivirulesConditions_Contact_HasTag' => ['tn' => 'civicrm_contact'],
      'CRM_CivirulesConditions_Activity_HasTag' => ['tn' => 'civicrm_activity'],
      'CRM_CivirulesConditions_Case_HasTag' => ['tn' => 'civicrm_case'],
      'CRM_CivirulesConditions_File_HasTag' => ['tn' => 'civicrm_file'],
    ];
    foreach ($expected as $className => $params) {
      $object = new $className();
      $this->assertSame($params, $object->getConfigFormParams(), $className);
      $this->assertNotNull($object->getConfigFormPath(), $className);
    }
  }

  /**
   * These two build their params from instance state rather than a literal.
   */
  public function testConditionsWithDynamicExtraParams(): void {
    $hasRecur = new \CRM_CivirulesConditions_Contact_HasRecur();
    $params = $hasRecur->getConfigFormParams();
    $this->assertArrayHasKey('entity', $params);
    $this->assertArrayHasKey('condition_name', $params);
    $this->assertSame('civicrm/civirule/form/condition/status', $hasRecur->getConfigFormPath());

    $donor = new \CRM_CivirulesConditions_ContributionRecur_DonorIsRecurring();
    $this->assertArrayHasKey('condition_name', $donor->getConfigFormParams());
  }

  /**
   * The route recorded by the helper must match the one the old URL used, or the
   * two entry points would lead to different forms.
   */
  public function testRecordedPathMatchesTheGeneratedUrl(): void {
    foreach ($this->allClasses() as [$label, $object]) {
      $path = $object->getConfigFormPath();
      if ($path === NULL) {
        continue;
      }
      $url = $object->getExtraDataInputUrl(123);
      $this->assertStringContainsString(
        rtrim($path, '/'),
        (string) $url,
        "{$label}: recorded path '{$path}' is not present in the generated url '{$url}'"
      );
    }
  }

}
