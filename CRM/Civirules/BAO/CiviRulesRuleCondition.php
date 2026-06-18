<?php

use CRM_Civirules_ExtensionUtil as E;
use Civi\Api4\CiviRulesRuleCondition;

/**
 * BAO RuleCondition for CiviRule Rule Condition
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_Civirules_BAO_CiviRulesRuleCondition extends CRM_Civirules_DAO_RuleCondition implements \Civi\Core\HookInterface {

  /**
   * Function to disable a rule condition
   *
   * @param int $ruleConditionId
   *
   * @throws Exception when ruleConditionId is empty
   */
  public static function disable($ruleConditionId) {
    if (!empty($ruleConditionId)) {
      CiviRulesRuleCondition::update(FALSE)
        ->addValue('is_active', 0)
        ->addWhere('id', '=', $ruleConditionId)
        ->execute();
    }
  }

  /**
   * Function to enable a rule condition
   *
   * @param int $ruleConditionId
   *
   * @throws Exception when ruleConditionId is empty
   */
  public static function enable($ruleConditionId) {
    if (!empty($ruleConditionId)) {
      CiviRulesRuleCondition::update(FALSE)
        ->addValue('is_active', 1)
        ->addWhere('id', '=', $ruleConditionId)
        ->execute();
    }
  }

  /**
   * Function to count the number of conditions for a rule
   *
   * @param int $ruleId
   *
   * @return int
   */
  public static function countConditionsForRule($ruleId) {
    return CiviRulesRuleCondition::get(FALSE)
      ->addWhere('rule_id', '=', $ruleId)
      ->execute()->count();
  }

  /**
   * Callback for hook_civicrm_post().
   * @param \Civi\Core\Event\PostEvent $event
   */
  public static function self_hook_civicrm_post(\Civi\Core\Event\PostEvent $event) {
    if (isset(\Civi::$statics[__CLASS__]['validateconditionlinks'])) {
      return;
    }

    if (in_array($event->action, ['create', 'edit'])) {
      CRM_Utils_Weight::correctDuplicateWeights('CRM_Civirules_DAO_CiviRulesRuleCondition');
    }
    if (property_exists($event->object, 'rule_id') && !empty($event->object->rule_id)) {
      $ruleID = $event->object->rule_id;
    }
    elseif (!empty($event->id)) {
      $ruleID = \Civi\Api4\CiviRulesRuleCondition::get(FALSE)
        ->addSelect('rule_id')
        ->addWhere('id', '=', $event->id)
        ->execute()
        ->first()['rule_id'];
    }
    if ($ruleID && !isset(\Civi::$statics[__CLASS__]['validateconditionlinks'])) {
      self::checkAndValidateConditionLinks($ruleID);
    }
    unset(\Civi::$statics[__CLASS__]['validateconditionlinks']);
  }

  /**
   * @param int $ruleID
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  private static function checkAndValidateConditionLinks(int $ruleID): void {
    \Civi::$statics[__CLASS__]['validateconditionlinks'] = TRUE;
    $ruleConditions = CiviRulesRuleCondition::get(FALSE)
      ->addSelect('id', 'condition_link')
      ->addWhere('rule_id', '=', $ruleID)
      ->addOrderBy('weight', 'ASC')
      ->addOrderBy('id', 'ASC')
      ->execute();
    foreach ($ruleConditions as $index => $condition) {
      if ($index === 0) {
        if (!empty($condition['condition_link'])) {
          $records[] = [
            'id' => $condition['id'],
            'condition_link' => NULL,
          ];
        }
      }
      else {
        if (empty($condition['condition_link'])) {
          $records[] = [
            'id' => $condition['id'],
          // Default to AND if not set.
            'condition_link' => 'AND',
          ];
        }
      }
    }
    if (!empty($records)) {
      CiviRulesRuleCondition::save(FALSE)
        ->setRecords($records)
        ->setMatch(['id'])
        ->execute();
    }
  }

  /**
   * Function to unserialize the CiviRulesRuleCondition condition_params
   *
   * @return array
   */
  public function unserializeParams(): array {
    if (!empty($this->condition_params) && !is_array($this->condition_params)) {
      // Deprecated compatibility check - remove once all data migrated to array storage
      return is_array($this->condition_params) ? $this->condition_params : unserialize($this->condition_params);
    }
    return [];
  }

  public static function getConditionLinkOptions() {
    return [
      'AND' => E::ts('AND'),
      'OR' => E::ts('OR'),
    ];
  }

}
