<?php
/**
 * @author Véronique Gratioulet <veronique.gratioulet@atd-quartmonde.org>
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
/**
 * Trigger when an Contact Custom Data changes.

 */
class CRM_CivirulesPostTrigger_ContactCustomDataChanged extends CRM_Civirules_Trigger {

  protected static function getObjectName() {
    return 'Contact';
  }

  private static function getTriggers() {
    $get_called_class = get_called_class();
    $triggers = CRM_Civirules_BAO_Rule::findRulesByClassname($get_called_class);

    $contactTriggers = CRM_Civirules_BAO_Rule::findRulesByObjectNameAndOp($get_called_class::getObjectName(), 'edit');
    foreach($contactTriggers as $trigger) {
      if ($trigger instanceof CRM_Civirules_Trigger_Post) {
        $triggers[] = $trigger;
      }
    }

    return $triggers;
  }

  public function reactOnEntity() {
    $get_called_class = get_called_class();
    $objectName = $get_called_class::getObjectName();
    return new CRM_Civirules_TriggerData_EntityDefinition($objectName, $objectName, $get_called_class::getDaoClassName(), 'Contact');
  }

  /**
   * Returns an array of additional entities provided in this trigger
   *
   * @return array of CRM_Civirules_TriggerData_EntityDefinition
   */
  protected function getAdditionalEntities() {
    $entities = parent::getAdditionalEntities();
    return $entities;
  }

  /**
   * Return the name of the DAO Class. If a dao class does not exist return an empty value
   *
   * @return string
   */
  protected function getDaoClassName() {
    return 'CRM_Contact_DAO_Contact';
  }

  protected static function getEntityExtensions() {
    $get_called_class = get_called_class();
    $objectName = $get_called_class::getObjectName();
    if ('Contact' == $objectName) {
      $entity_extensions = array('Contact', 'Individual', 'Organization', 'Household');
    } else {
      $entity_extensions = array($objectName);
    }
    return $entity_extensions;
  }

  public static function custom($op, $groupID, $entityID, &$params) {
    $config = \Civi\CiviRules\Config\ConfigContainer::getInstance();
    $custom_group = $config->getCustomGroupById($groupID);
    $get_called_class = get_called_class();
    $objectName = $get_called_class::getObjectName();
    $entity_extensions = self::getEntityExtensions();
    if (!in_array($custom_group['extends'] , $entity_extensions)) {
      return;
    }
    $contact = array();
    if (!empty($entityID)) {
      $contact = civicrm_api3('Contact', 'getsingle', ['id' => $entityID]);
      foreach ($params as $field) {
        if (!empty($field['custom_field_id'])) {
          $contact['custom_' . $field['custom_field_id']] = $field['value'];
        }
      }
    }

    $oldData = CRM_Civirules_Utils_PreData::getPreData($objectName, $entityID, 1);
    $triggerData = new CRM_Civirules_TriggerData_Edit('Contact', $entityID, $contact, $oldData);

    self::trigger($triggerData);
  }

  protected static function trigger(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    //find matching rules for this objectName and op
    $triggers = self::getTriggers();
    foreach($triggers as $trigger) {
      $trigger->alterTriggerData($triggerData);
      CRM_Civirules_Engine::triggerRule($trigger, $triggerData);
    }
  }

}
