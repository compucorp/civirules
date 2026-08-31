<?php

use CRM_Civirules_ExtensionUtil as E;

class CRM_CivirulesCronTrigger_RelationshipEndDate extends CRM_Civirules_Trigger_Cron {

  private $dao = false;

  /**
   * Selectable units for the "ended within the last X ..." window.
   *
   * @return array
   */
  public static function intervals() {
    return [
      'days' => E::ts('Day(s) in the past'),
      'weeks' => E::ts('Week(s) in the past'),
      'months' => E::ts('Month(s) in the past'),
      'years' => E::ts('Year(s) in the past'),
    ];
  }

  /**
   * Returns an array of entities on which the triggerreacts
   *
   * @return CRM_Civirules_TriggerData_EntityDefinition
   */
  protected function reactOnEntity() {
    return new CRM_Civirules_TriggerData_EntityDefinition('Relationship', 'Relationship', 'CRM_Contact_DAO_Relationship', 'Relationship');
  }

  /**
   * This method returns a CRM_Civirules_TriggerData_TriggerData this entity is used for triggering the rule
   *
   * Return false when no next entity is available
   *
   * @return object|bool CRM_Civirules_TriggerData_TriggerData|false
   * @access protected
   */
  protected function getNextEntityTriggerData() {
    if (!$this->dao) {
      $this->queryForTriggerEntities();
    }
    if ($this->dao->fetch()) {
      $data = array();
      CRM_Core_DAO::storeValues($this->dao, $data);
      return new CRM_Civirules_TriggerData_Cron($this->dao->contact_id_a, 'Relationship', $data, NULL, $this);
    }
    return false;
  }

  /**
   * Method to query trigger entities
   *
   * @access private
   */
  private function queryForTriggerEntities() {
    $params = [1 => [$this->ruleId, 'Integer']];

    $intervalSql = $this->getIntervalSql($params);
    if ($intervalSql) {
      $sql = "SELECT `r`.*
              FROM `civicrm_relationship` `r`
              LEFT JOIN `civirule_rule_log` `rule_log`
                ON `rule_log`.`rule_id` = %1
                AND `rule_log`.`entity_table` = 'civicrm_relationship'
                AND `rule_log`.`entity_id` = `r`.`id`
                AND `rule_log`.`log_date` >= `r`.`end_date`
              WHERE `r`.`end_date` IS NOT NULL
              AND `r`.`end_date` < CURDATE()
              AND `r`.`end_date` >= {$intervalSql}
              AND `rule_log`.`id` IS NULL";
    }
    else {
      $sql = "SELECT r.*
              FROM `civicrm_relationship` `r`
              LEFT JOIN (
                  SELECT * FROM `civicrm_relationship`
              ) `r_inner`
              ON `r`.`relationship_type_id` = `r_inner`.`relationship_type_id`
              AND `r`.`contact_id_a` = `r_inner`.`contact_id_a`
              AND (`r`.`end_date` < `r_inner`.`end_date` or `r_inner`.`end_date` is null)
              AND `r`.`id` != `r_inner`.`id`
              WHERE `r_inner`.`id` IS NULL
              AND `r`.`end_date` IS NOT NULL
              AND `r`.`end_date` < CURDATE()
              AND `r`.`contact_id_a` NOT IN (
                SELECT `rule_log`.`contact_id`
                FROM `civirule_rule_log` `rule_log`
                WHERE `rule_log`.`rule_id` = %1 AND `rule_log`.`log_date` >= CURDATE()
              );";
    }
    $this->dao = CRM_Core_DAO::executeQuery($sql, $params, true, 'CRM_Contact_BAO_Relationship');
  }

  /**
   * Builds the SQL expression for the start of the end date window from the trigger params.
   *
   * Returns false when no (valid) window is configured.
   *
   * @param array $params
   * @return string|bool
   */
  private function getIntervalSql(&$params) {
    if (empty($this->triggerParams['interval']) || empty($this->triggerParams['interval_unit'])) {
      return false;
    }

    $units = [
      'days' => 'DAY',
      'weeks' => 'WEEK',
      'months' => 'MONTH',
      'years' => 'YEAR',
    ];
    if (!isset($units[$this->triggerParams['interval_unit']])) {
      return false;
    }
    $params[2] = [$this->triggerParams['interval'], 'Integer'];

    return "DATE_SUB(CURDATE(), INTERVAL %2 " . $units[$this->triggerParams['interval_unit']] . ")";
  }

  /**
   * Returns a redirect url to extra data input from the user after adding a trigger
   *
   * Return false if you do not need extra data input
   *
   * @param int $ruleId
   * @return bool|string
   */
  public function getExtraDataInputUrl($ruleId) {
    return CRM_Utils_System::url('civicrm/civirule/form/trigger/relationshipenddate', 'rule_id=' . $ruleId);
  }

  /**
   * Returns a description of this trigger
   *
   * @return string
   */
  public function getTriggerDescription(): string {
    if (empty($this->triggerParams['interval']) || empty($this->triggerParams['interval_unit'])) {
      return E::ts('Relationship end date reached (any end date in the past)');
    }

    $intervals = self::intervals();
    return E::ts('Relationship end date reached within the last %1 %2', [
      1 => $this->triggerParams['interval'],
      2 => $intervals[$this->triggerParams['interval_unit']] ?? $this->triggerParams['interval_unit'],
    ]);
  }

  /**
   * Get various types of help text for the trigger.
   *
   * @param string $context
   *
   * @return string
   */
  public function getHelpText(string $context = 'triggerParamsHelp'): string {
    switch ($context) {
      case 'triggerDescriptionWithParams':
        return $this->getTriggerDescription();

      case 'triggerDescription':
        return E::ts('Trigger for relationships whose end date has been reached.');

      case 'triggerParamsHelp':
        return E::ts('Only relationships that ended within the selected period are picked up, and each relationship triggers the rule only once per end date.');

      default:
        return parent::getHelpText($context);
    }
  }

}
