<?php

class CRM_CivirulesPostTrigger_GroupContact extends CRM_Civirules_Trigger_Post {

  /**
   * Rows to use instead of reading them, keyed by group id.
   *
   * Set while a deferred trigger runs, so it sees the rows as they were when it
   * was queued rather than as a later save in the same request left them.
   *
   * @var array
   */
  public static $groupContactsAsQueued = [];

  /**
   * Returns an array of entities on which the trigger reacts
   *
   * @return CRM_Civirules_TriggerData_EntityDefinition
   */
  protected function reactOnEntity() {
    return new CRM_Civirules_TriggerData_EntityDefinition($this->objectName, $this->objectName, $this->getDaoClassName(), 'GroupContact');
  }

  /**
   * Returns an array of additional entities provided in this trigger
   *
   * @return array of CRM_Civirules_TriggerData_EntityDefinition
   */
  protected function getAdditionalEntities() {
    $entities = parent::getAdditionalEntities();
    $entities[] = new CRM_Civirules_TriggerData_EntityDefinition('Group', 'Group', 'CRM_Contact_DAO_Group', 'Group');
    return $entities;
  }

  /**
   * Return the name of the DAO Class. If a dao class does not exist return an empty value
   *
   * @return string
   */
  protected function getDaoClassName() {
    return 'CRM_Contact_DAO_GroupContact';
  }

  /**
   * Trigger a rule for this trigger
   *
   * @param string $op
   * @param string $objectName
   * @param int $objectId
   * @param object $objectRef
   * @param string $eventID
   */
  public function triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID) {
    // $objectRef could be either an array of contact ids or it is an object of type CRM_Contact_BAO_GroupContact.
    // So check with which signature we are dealing.
    if (is_object($objectRef)) {
      // We are dealing with the objectRef is an instance of CRM_Contact_DAO_GroupContact.
      $group = civicrm_api3('Group', 'getsingle', ['id' => $objectRef->group_id]);
      $data = [];
      CRM_Core_DAO::storeValues($objectRef, $data);
      $triggerData = $this->getTriggerDataFromPost($op, $objectName, $objectId, $data, $eventID);
      $triggerData->setEntityData('Group', $group);
      $this->setTriggerData($triggerData);
      parent::triggerTrigger($op, $objectName, $objectId, $objectRef, $eventID);
    } else {
      // We are dealing with an array of contact ids.
      $rows = self::$groupContactsAsQueued[$objectId] ?? self::findGroupContacts((int) $objectId, $objectRef);
      $group = civicrm_api3('Group', 'getsingle', ['id' => $objectId]);
      // The queued rows are for this trigger only. Hide them while its rules
      // run, so a GroupContact post fired by an action reads its own rows.
      $queued = self::$groupContactsAsQueued;
      self::$groupContactsAsQueued = [];
      try {
        foreach ($rows as $row) {
          $dao = new CRM_Contact_DAO_GroupContact();
          foreach ($row as $field => $value) {
            $dao->$field = $value;
          }
          $triggerData = $this->getTriggerDataFromPost($op, $objectName, $dao->id, $dao, $eventID);
          $triggerData->setEntityData('Group', $group);
          CRM_Civirules_Engine::triggerRule($this, $triggerData);
        }
      }
      finally {
        self::$groupContactsAsQueued = $queued;
      }
    }
  }

  /**
   * Returns the latest group contact row of each contact in a group.
   *
   * @param int $groupId
   * @param array $contactIds
   *
   * @return array
   */
  public static function findGroupContacts(int $groupId, array $contactIds) {
    $contactIds = array_filter(array_map('intval', $contactIds));
    if (empty($contactIds)) {
      return [];
    }
    $sql = "SELECT MAX(`id`) AS id, `group_id`, `contact_id`, `status`, `location_id`, `email_id`
          FROM `civicrm_group_contact`
          WHERE `group_id` = %1 AND `contact_id` IN (" . implode(", ", $contactIds) . ")
          GROUP BY `contact_id`";
    $params[1] = [$groupId, 'Integer'];
    $dao = CRM_Core_DAO::executeQuery($sql, $params);
    $rows = [];
    while ($dao->fetch()) {
      $rows[] = [
        'id' => $dao->id,
        'group_id' => $dao->group_id,
        'contact_id' => $dao->contact_id,
        'status' => $dao->status,
        'location_id' => $dao->location_id,
        'email_id' => $dao->email_id,
      ];
    }
    return $rows;
  }

}
