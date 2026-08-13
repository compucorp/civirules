<?php

class CRM_CivirulesConditions_Utils_Membership {

  /**
   * @param int $ruleId
   * @return bool
   */
  private static function hasMembershipPaymentCondition(int $ruleId): bool {
    $hasCondition = FALSE;
    try {
      $count = \Civi\Api4\CiviRulesRuleCondition::get(FALSE)
        ->addWhere('rule_id', '=', $ruleId)
        ->addWhere('condition_id.class_name', '=', 'CRM_CivirulesConditions_Contribution_IsMembershipPayment')
        ->execute()->count();
      if ($count > 0) {
        $hasCondition = TRUE;
      }
    }
    catch (CRM_Core_Exception $ex) {
    }
    return $hasCondition;
  }

  /**
   * @param CRM_Civirules_Trigger $trigger
   * @param CRM_Civirules_BAO_CiviRulesRule $rule
   * @return bool
   */
  public static function checkMembershipEntities(CRM_Civirules_Trigger $trigger, CRM_Civirules_BAO_CiviRulesRule $rule): bool {
    $works = FALSE;
    $entities = $trigger->getProvidedEntities();
    if (self::hasMembershipPaymentCondition($rule->id)) {
      if ($entities['Membership'] || $entities['Contribution']) {
        $works = TRUE;
      }
    }
    else {
      if ($entities['Membership']) {
        $works = TRUE;
      }
    }
    return $works;
  }

}
