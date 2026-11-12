<?php
/**
 * BAO RuleAction for CiviRule Rule Action
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
class CRM_Civirules_BAO_CiviRulesRuleAction extends CRM_Civirules_DAO_CiviRulesRuleAction implements \Civi\Core\HookInterface {

  /**
   * Function to unserialize the CiviRulesRuleAction action_params
   *
   * @return array
   */
  public function unserializeParams(): array {
    if (!empty($this->action_params)) {
      // Deprecated compatibility check - remove once all data migrated to array storage
      return is_array($this->action_params) ? $this->action_params : unserialize($this->action_params, ['allowed_classes' => FALSE]);
    }
    return [];
  }

  /**
   * Callback for hook_civicrm_post().
   * @param \Civi\Core\Event\PostEvent $event
   */
  public static function self_hook_civicrm_post(\Civi\Core\Event\PostEvent $event) {
    if (!in_array($event->action, ['create', 'edit'])) {
      return;
    }
    // Scope to the rule being edited: unscoped, this treats the whole table as one
    // ordered list and renumbers every other rule's actions too.
    $ruleId = $event->object->rule_id ?? CRM_Core_DAO::getFieldValue('CRM_Civirules_DAO_CiviRulesRuleAction', $event->id, 'rule_id');
    if (empty($ruleId)) {
      return;
    }
    CRM_Utils_Weight::correctDuplicateWeights('CRM_Civirules_DAO_CiviRulesRuleAction', ['rule_id' => $ruleId]);
  }

}
