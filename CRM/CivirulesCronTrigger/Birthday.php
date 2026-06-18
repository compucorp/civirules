<?php
/**
 * Class for CiviRules CronTrigger Birthday
 *
 * @author Jaap Jansma (CiviCooP) <jaap.jansma@civicoop.org>
 * @license AGPL-3.0
 */
class CRM_CivirulesCronTrigger_Birthday extends CRM_Civirules_Trigger_Cron {

  /**
   * @var \CRM_Contact_DAO_Contact
   */
  private $dao = NULL;

  public function getEntityName(): ?string {
    return 'Contact';
  }

  /**
   * Returns an array of entities on which the t riggerreacts
   *
   * @return CRM_Civirules_TriggerData_EntityDefinition
   */
  protected function reactOnEntity() {
    return new CRM_Civirules_TriggerData_EntityDefinition(ts('Person'), 'Contact', 'CRM_Contact_DAO_Contact', 'Contact');
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
      $data = [];
      CRM_Core_DAO::storeValues($this->dao, $data);
      return new CRM_Civirules_TriggerData_Cron($this->dao->id, 'Contact', $data, NULL, $this);
    }
    return FALSE;
  }

  /**
   * Method to query trigger entities
   *
   * @access private
   */
  private function queryForTriggerEntities() {
    $sql = "SELECT c.*
            FROM `civicrm_contact` `c`
            WHERE `c`.`birth_date` IS NOT NULL
            AND DAY(`c`.`birth_date`) = DAY(NOW())
            AND MONTH(`c`.`birth_date`) = MONTH(NOW())
            AND c.is_deceased = 0 and c.is_deleted = 0
            AND `c`.`id` NOT IN (
              SELECT `rule_log`.`contact_id`
              FROM `civirule_rule_log` `rule_log`
              WHERE `rule_log`.`rule_id` = %1 AND DATE(`rule_log`.`log_date`) = DATE(NOW())
            )";
    $params[1] = [$this->ruleId, 'Integer'];
    $this->dao = CRM_Core_DAO::executeQuery($sql, $params, TRUE, 'CRM_Contact_BAO_Contact');
  }

}
