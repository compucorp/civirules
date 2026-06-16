<?php

/**
 * CiviRules Condition: URL Contains (with wildcard support).
 *
 * @license AGPL-3.0
 */
class CRM_CivirulesConditions_PageView_UrlContains extends CRM_Civirules_Condition {

  /**
   * @var array Unserialized condition params.
   */
  protected array $conditionParams = [];

  /**
   * {@inheritdoc}
   */
  public function setRuleConditionData(array $ruleCondition) {
    parent::setRuleConditionData($ruleCondition);
    $this->conditionParams = [];
    if (!empty($this->ruleCondition['condition_params'])) {
      $this->conditionParams = unserialize($this->ruleCondition['condition_params']);
    }
  }

  /**
   * Returns TRUE when the current URL matches the configured pattern.
   *
   * {@inheritdoc}
   */
  public function isConditionValid(CRM_Civirules_TriggerData_TriggerData $triggerData): bool {
    $pattern = trim($this->conditionParams['url_pattern'] ?? '');

    if ($pattern === '') {
      return TRUE;
    }

    if ($triggerData instanceof CRM_Civirules_TriggerData_PageView) {
      $url = $triggerData->getUrl();
    }
    else {
      $pageViewData = $triggerData->getEntityData('PageView');
      $url = $pageViewData['url'] ?? '';
    }

    if (empty($url)) {
      return FALSE;
    }

    $url     = ltrim($url, '/');
    $pattern = ltrim($pattern, '/');

    if (strpos($pattern, '?') === FALSE) {
      $url = strtok($url, '?');
    }

    return fnmatch($pattern, $url);
  }

  /**
   * Returns the URL for the condition configuration form.
   *
   * {@inheritdoc}
   */
  public function getExtraDataInputUrl($ruleConditionId): string {
    return CRM_Utils_System::url(
      'civicrm/civirule/form/condition/pageview/urlcontains',
      'rule_condition_id=' . $ruleConditionId,
      FALSE, NULL, FALSE, FALSE, TRUE
    );
  }

  /**
   * Human-readable summary of the configured pattern.
   *
   * {@inheritdoc}
   */
  public function userFriendlyConditionParams(): string {
    $pattern = $this->conditionParams['url_pattern'] ?? '';
    if (empty($pattern)) {
      return ts('URL: (any — no pattern set)');
    }
    return ts('URL matches pattern: %1', [1 => $pattern]);
  }

  /**
   * {@inheritdoc}
   */
  public function exportConditionParameters(): array {
    return $this->conditionParams;
  }

  /**
   * {@inheritdoc}
   */
  public function importConditionParameters($condition_params = NULL): string {
    if (!empty($condition_params)) {
      return serialize($condition_params);
    }
    return '';
  }

}
