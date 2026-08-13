<?php
use CRM_Civirules_ExtensionUtil as E;
/**
 * Class CRM_CivirulesConditions_Contribution_IsMembershipPayment
 *
 * This CiviRules condition will check whether a contribution is a membership contribution and if so, add membership to entity data
 *
 * @author Noah Miller (Lemniscus) <nm@lemnisc.us>
 * @license AGPL-3.0
 */
class CRM_CivirulesConditions_Contribution_IsMembershipPayment extends CRM_Civirules_Condition {

  /**
   * Checks if the condition is met
   *
   * @param CRM_Civirules_TriggerData_TriggerData $triggerData
   * @return bool
   * @access public
   */
  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $contribution = $triggerData->getEntityData('Contribution');
    $contributionId = $triggerData->getEntityId();
    if ($contributionId && $this->isMembershipPayment($contributionId)) {
      $this->addMembershipEntity($contributionId, $triggerData);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * @param int $contributionId
   * @param CRM_Civirules_TriggerData_TriggerData $triggerData
   * @return void
   */
  private function addMembershipEntity(int $contributionId, CRM_Civirules_TriggerData_TriggerData $triggerData): void {
    try {
      $lineItem = \Civi\Api4\LineItem::get(TRUE)
        ->addSelect('membership.*')
        ->addJoin('Membership AS membership', 'LEFT', ['entity_id', '=', 'membership.id'])
        ->addWhere('contribution_id', '=', $contributionId)
        ->addWhere('entity_table', '=', 'civicrm_membership')
        ->execute()->first();
      if ($lineItem['id']) {
        $membership = [];
        foreach ($lineItem as $fieldName => $fieldValue) {
          if (str_starts_with($fieldName, 'membership.')) {
            $membership[str_replace('membership.', '', $fieldName)] = $fieldValue;
          }
        }
        $triggerData->setEntityData('Membership', $membership);
      }
    }
    catch (\CRM_Core_Exception $ex) {
    }
  }

  /**
   * @param int $contributionId
   * @return bool
   */
  private function isMembershipPayment(int $contributionId): bool {
    $isMembershipPayment = FALSE;
    try {
      $lineItem = \Civi\Api4\LineItem::get(FALSE)
        ->addSelect('entity_table')
        ->addWhere('contribution_id', '=', $contributionId)
        ->execute()->first();
      if ($lineItem['entity_table'] && $lineItem['entity_table'] == 'civicrm_membership') {
        $isMembershipPayment = TRUE;
      }
    }
    catch (\CRM_Core_Exception $ex) {
    }
    return $isMembershipPayment;
  }

  /**
   * Returns a redirect url to extra data input from the user after adding a condition
   *
   * @param int $ruleConditionId
   * @return bool
   * @access public
   * @abstract
   */
  public function getExtraDataInputUrl($ruleConditionId): bool {
    return FALSE;
  }

  /**
   * Returns a user friendly text explaining the condition params
   *
   * @return string
   * @access public
   */
  public function userFriendlyConditionParams(): string {
    return E::ts('Contribution is Membership Payment?');
  }

  /**
   * This function validates whether this condition works with the selected trigger.
   *
   * @param CRM_Civirules_Trigger $trigger
   * @param CRM_Civirules_BAO_CiviRulesRule $rule
   * @return bool
   */
  public function doesWorkWithTrigger(CRM_Civirules_Trigger $trigger, CRM_Civirules_BAO_CiviRulesRule $rule): bool {
    return $trigger->doesProvideEntity('Contribution');
  }

}
