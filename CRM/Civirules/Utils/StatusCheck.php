<?php

use CRM_Civirules_ExtensionUtil as E;

/**
 * System status checks for CiviRules.
 */
class CRM_Civirules_Utils_StatusCheck {

  const NAME = 'civiRulesUnconfigured';

  /**
   * Rules listed individually before the message is truncated.
   */
  private const MAX_RULES_LISTED = 20;

  /**
   * Report rules containing an action or condition that was never configured.
   *
   * @return array
   */
  public static function checkUnconfigured(): array {
    $rules = [];
    foreach (['action', 'condition'] as $type) {
      $entity = $type === 'action' ? 'CiviRulesRuleAction' : 'CiviRulesRuleCondition';
      try {
        $result = civicrm_api4($entity, 'get', [
          'checkPermissions' => FALSE,
          'select' => ['id', 'rule_id', 'rule_id.label', 'rule_id.is_active'],
          'where' => [['is_configured', '=', FALSE]],
          'orderBy' => ['rule_id' => 'ASC'],
        ]);
      }
      catch (Throwable $e) {
        // A status check must never be the thing that breaks the status page.
        Civi::log('civirules')->warning('CiviRules: unconfigured check failed: {msg}', ['msg' => $e->getMessage()]);
        return [];
      }
      foreach ($result as $row) {
        $ruleId = $row['rule_id'];
        $rules[$ruleId]['label'] = $row['rule_id.label'];
        $rules[$ruleId]['is_active'] = $row['rule_id.is_active'];
        $rules[$ruleId][$type] = ($rules[$ruleId][$type] ?? 0) + 1;
      }
    }
    if (!$rules) {
      return [];
    }

    // An unconfigured item only actually does harm on a rule that is running.
    $anyActive = (bool) array_filter(array_column($rules, 'is_active'));

    $message = new CRM_Utils_Check_Message(
      self::NAME,
      self::buildMessage($rules),
      E::ts('CiviRules: %1 rule(s) have unconfigured actions or conditions', [1 => count($rules)]),
      $anyActive ? \Psr\Log\LogLevel::WARNING : \Psr\Log\LogLevel::NOTICE,
      'fa-exclamation-triangle',
      'CiviRules'
    );
    return [$message];
  }

  private static function buildMessage(array $rules): string {
    $html = '<p>' . E::ts('These CiviRules contain an action or condition that was added but never configured. An unconfigured condition is treated as not met, so the rule never fires; an unconfigured action is skipped. Edit each rule and either configure or remove the highlighted rows.') . '</p><ul>';
    foreach (array_slice($rules, 0, self::MAX_RULES_LISTED, TRUE) as $ruleId => $rule) {
      $counts = [];
      if (!empty($rule['condition'])) {
        $counts[] = E::ts('%count condition', ['plural' => '%count conditions', 'count' => $rule['condition']]);
      }
      if (!empty($rule['action'])) {
        $counts[] = E::ts('%count action', ['plural' => '%count actions', 'count' => $rule['action']]);
      }
      $html .= '<li><a href="' . CRM_Utils_System::url('civicrm/civirule/form/rule', 'action=update&id=' . $ruleId) . '">'
        . htmlspecialchars($rule['label'] ?? ('#' . $ruleId))
        . '</a> - ' . implode(', ', $counts)
        . (empty($rule['is_active']) ? ' ' . E::ts('(rule is disabled)') : '')
        . '</li>';
    }
    $html .= '</ul>';
    if (count($rules) > self::MAX_RULES_LISTED) {
      $html .= '<p>' . E::ts('...and %1 more.', [1 => count($rules) - self::MAX_RULES_LISTED]) . '</p>';
    }
    $html .= '<p>' . E::ts('To review them all, search CiviRules Rule Conditions or Rule Actions in SearchKit with "Is Configured" set to No.') . '</p>';
    return $html;
  }

}
