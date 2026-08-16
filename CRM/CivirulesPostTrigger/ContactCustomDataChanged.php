<?php
/**
 * @author Véronique Gratioulet <veronique.gratioulet@atd-quartmonde.org>
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */

/**
 * Trigger when an Contact Custom Data changes.
 * @fixme: This should probably extend CRM_Civirules_Trigger_Post
 */
class CRM_CivirulesPostTrigger_ContactCustomDataChanged extends CRM_Civirules_Trigger {

  /**
   * @return string
   */
  protected static function getObjectName() {
    return 'Contact';
  }

  /**
   * @return array
   */
  private static function getTriggers() {
    $get_called_class = get_called_class();
    $triggers = CRM_Civirules_BAO_CiviRulesRule::findRulesByClassname($get_called_class);

    $contactTriggers = CRM_Civirules_BAO_CiviRulesRule::findRulesByObjectNameAndOp($get_called_class::getObjectName(), 'edit');
    foreach ($contactTriggers as $trigger) {
      if ($trigger instanceof CRM_Civirules_Trigger_Post) {
        $triggers[] = $trigger;
      }
    }

    return $triggers;
  }

  /**
   * @return \CRM_Civirules_TriggerData_EntityDefinition
   */
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

  /**
   * @return array|string[]
   */
  protected static function getEntityExtensions() {
    $get_called_class = get_called_class();
    $objectName = $get_called_class::getObjectName();
    if ('Contact' == $objectName) {
      $entity_extensions = ['Contact', 'Individual', 'Organization', 'Household'];
    }
    else {
      $entity_extensions = [$objectName];
    }
    return $entity_extensions;
  }

  /**
   * Contacts already fetched during the CURRENT hook invocation.
   *
   * hook_civicrm_custom invokes five of these classes in a row (Case, Contact,
   * Individual, Organization, Household) with identical arguments. Their entity
   * lists overlap by design: getEntityExtensions() returns
   * ['Contact','Individual','Organization','Household'] for 'Contact' and the
   * own name for the subtypes, so that the "of any Type" trigger also fires for
   * subtypes. As a result a write to a custom group extending Individual passes
   * the guard in both the Contact and the Individual class, and each performed
   * the same full Contact.getsingle for the same row.
   *
   * The cache deliberately lives for one hook invocation only:
   * civirules_civicrm_custom() clears it up front, so a later write to the same
   * contact can never observe stale data.
   *
   * Re-entrancy needs no stack here (unlike the one in
   * CRM_Civirules_Trigger_Post). If a rule action modifies the contact and that
   * fires a nested hook_civicrm_custom, the nested invocation clears this cache
   * and repopulates it with the post-modification row. The outer classes that
   * still have to run then read exactly what an uncached lookup would have
   * returned at that point — the freshest data — so nesting cannot make the
   * result diverge from the unpatched behaviour.
   *
   * @var array
   */
  private static array $contactCache = [];

  /**
   * Clears the per-invocation contact cache. Called from
   * civirules_civicrm_custom() before the five classes run.
   *
   * @return void
   */
  public static function resetContactCache() {
    self::$contactCache = [];
  }

  /**
   * @param string $op
   * @param int $groupID
   * @param int $entityID
   * @param array $params
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public static function custom($op, $groupID, $entityID, &$params) {
    $config = \Civi\CiviRules\Config\ConfigContainer::getInstance();
    $custom_group = $config->getCustomGroupById($groupID);
    $get_called_class = get_called_class();
    $objectName = $get_called_class::getObjectName();
    $entity_extensions = self::getEntityExtensions();
    if (!in_array($custom_group['extends'], $entity_extensions)) {
      return;
    }
    $contact = [];
    if (!empty($entityID)) {
      if (!array_key_exists($entityID, self::$contactCache)) {
        self::$contactCache[$entityID] = civicrm_api3('Contact', 'getsingle', ['id' => $entityID]);
      }
      // Copy: below, each class augments the contact with the custom fields of
      // its own group, which must not leak into the cache for the next class.
      $contact = self::$contactCache[$entityID];
      foreach ($params as $field) {
        if (!empty($field['custom_field_id'])) {
          $value = $field['value'];
          if ($field['type'] === 'Timestamp' && is_string($value)) {
            $date = \DateTime::createFromFormat('YmdHis', $value);
            $value = $date ? $date->format('Y-m-d H:i:s') : NULL;
          }
          $contact['custom_' . $field['custom_field_id']] = $value;
          $contact['custom_' . $field['custom_field_id'] . '_group_id'] = $field['custom_group_id'];
          $contact['custom_' . $field['custom_field_id'] . '_entry_id'] = $field['id'] ?? NULL;
        }
      }
    }

    $oldData = CRM_Civirules_Utils_PreData::getPreData($objectName, $entityID, 1);
    $triggerData = new CRM_Civirules_TriggerData_Edit('Contact', $entityID, $contact, $oldData);

    self::trigger($triggerData);
  }

  /**
   * @param \CRM_Civirules_TriggerData_TriggerData $triggerData
   *
   * @return void
   */
  protected static function trigger(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    //find matching rules for this objectName and op
    $triggers = self::getTriggers();
    foreach ($triggers as $trigger) {
      $trigger->alterTriggerData($triggerData);
      CRM_Civirules_Engine::triggerRule($trigger, $triggerData);
    }
  }

}
