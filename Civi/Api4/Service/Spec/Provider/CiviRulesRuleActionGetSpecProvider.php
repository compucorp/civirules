<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Service\Spec\Provider;

use Civi\Api4\Service\Spec\FieldSpec;
use Civi\Api4\Service\Spec\RequestSpec;

/**
 * @service
 * @internal
 */
class CiviRulesRuleActionGetSpecProvider extends \Civi\Core\Service\AutoService implements Generic\SpecProviderInterface {

  /**
   * @param \Civi\Api4\Service\Spec\RequestSpec $spec
   *
   * @throws \CRM_Core_Exception
   */
  public function modifySpec(RequestSpec $spec): void {
    // Calculated field gets action parameter description
    $field = new FieldSpec('action_params_display', 'CiviRulesRuleAction', 'String');
    $field->setLabel(ts('Action Params Description'))
      ->setTitle(ts('Action Params Description'))
      ->setColumnName('action_params')
      ->setDescription(ts('Human-readable description of action parameters'))
      ->setType('Extra')
      ->setReadonly(TRUE)
      ->addOutputFormatter([__CLASS__, 'actionParamsDisplay']);
    $spec->addFieldSpec($field);

    // Calculated field gets action parameter description
    $field = new FieldSpec('action_delay_display', 'CiviRulesRuleAction', 'String');
    $field->setLabel(ts('Action Delay Description'))
      ->setTitle(ts('Action Delay Description'))
      ->setColumnName('delay')
      ->setDescription(ts('Human-readable description of action delay params'))
      ->setType('Extra')
      ->setReadonly(TRUE)
      ->addOutputFormatter([__CLASS__, 'actionDelayDisplay']);
    $spec->addFieldSpec($field);

    // Calculated so it cannot go stale: whether an action needs configuring is
    // defined by its class, not by anything stored on the row.
    $field = new FieldSpec('is_configured', 'CiviRulesRuleAction', 'Boolean');
    $field->setLabel(ts('Is Configured'))
      ->setTitle(ts('Is Configured'))
      ->setColumnName('action_params')
      ->setDescription(ts('FALSE if this action requires configuration but none has been saved'))
      ->setType('Extra')
      ->setReadonly(TRUE)
      ->setSqlRenderer(['CRM_Civirules_Utils_ConfigurationStatus', 'renderActionIsConfigured']);
    $spec->addFieldSpec($field);
  }

  /**
   * @param string $entity
   * @param string $action
   *
   * @return bool
   */
  public function applies($entity, $action): bool {
    return $entity === 'CiviRulesRuleAction' && in_array($action, ['get', 'create']);
  }

  /**
   * Format the action params for display
   *
   * @param $value
   * @param $row
   * @return void
   */
  public static function actionParamsDisplay(&$value, $row) {
    if (empty($row['action_id'])) {
      $value = '';
      return;
    }
    try {
      // $abort = FALSE: a row whose class was removed with an uninstalled extension
      // must not throw, or it takes out the whole SearchDisplay rather than one row.
      $actionClass = \CRM_Civirules_BAO_CiviRulesAction::getActionObjectById($row['action_id'], FALSE);
      if (!$actionClass) {
        $value = '⚠ ' . ts('Action class not found');
        return;
      }
      if (!\CRM_Civirules_Utils_ConfigurationStatus::isRuleActionConfigured(['action_params' => $value, 'action_id' => $row['action_id']])) {
        // userFriendlyConditionParams() would describe the missing parameters as
        // though they had been set, e.g. "unknown groups (), with statuses (Added)".
        $value = '⚠ ' . ts('Not configured');
        return;
      }
      $actionClass->setRuleActionData(['action_params' => $value]);
      $value = (string) $actionClass->userFriendlyConditionParams();
    }
    catch (\Throwable $e) {
      // userFriendlyConditionParams() implementations resolve stored ids against the
      // database, so a param referencing a deleted record can throw.
      \Civi::log('civirules')->warning('CiviRules: could not describe action params for RuleAction {id}: {msg}', [
        'id' => $row['id'] ?? NULL,
        'msg' => $e->getMessage(),
      ]);
      $value = '⚠ ' . ts('Unable to display configuration');
    }
  }

  /**
   * Format the delay params for display
   *
   * @param $value
   * @param $row
   * @return void
   */
  public static function actionDelayDisplay(&$value, $row) {
    if (!empty($row['action_id'])) {
      if (!empty($value)) {
        try {
          $delayClass = \CRM_Civirules_Delay_Factory::unserializeDelay($value);
          $value = $delayClass ? $delayClass->getDelayExplanation() : '';
        }
        catch (\Throwable $e) {
          $value = '';
        }
      }
    }
  }

}
