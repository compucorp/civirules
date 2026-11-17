<?php

class CRM_Civirules_TriggerData_Edit extends CRM_Civirules_TriggerData_Post implements CRM_Civirules_TriggerData_Interface_OriginalData {

  protected $originalData = [];

  /**
   * Contact id found by the fallback in getContactId().
   *
   * @var int|null
   */
  protected $fallbackContactId = NULL;

  /**
   * Whether getContactId() has run its fallback.
   *
   * @var bool
   */
  protected $fallbackContactIdResolved = FALSE;

  /**
   * @param string $entity
   * @param int $objectId
   * @param array $data
   * @param array $originalData
   * @param ?\CRM_Civirules_Trigger_Post $trigger
   *
   * @throws \CRM_Core_Exception
   * @throws \Civi\API\Exception\UnauthorizedException
   */
  public function __construct($entity, $objectId, $data, $originalData, ?CRM_Civirules_Trigger_Post $trigger = NULL) {
    parent::__construct($entity, $objectId, $data, $trigger);

    if (!is_array($originalData)) {
      throw new Exception('Original data is not set or is not an array in EditTriggerData for CiviRules');
    }
    $this->originalData = CRM_Civirules_Engine::useCanonicalFieldNames($entity, $originalData);
  }

  public function getOriginalData() {
    return $this->originalData;
  }

  public function getOriginalEntity() {
    return $this->getEntity();
  }

  /**
   * Returns the contact, falling back to the record's own contact.
   *
   * The fallback serves saves without contact_id and is cached on the object.
   *
   * @return int|string|null
   *   The trigger data's own contact id (int or numeric string), else the
   *   fallback as an int, or NULL when there is no contact.
   */
  public function getContactId() {
    $contactId = parent::getContactId();
    if (!empty($contactId)) {
      return $contactId;
    }
    if (!$this->fallbackContactIdResolved) {
      $this->fallbackContactId = $this->findFallbackContactId();
      $this->fallbackContactIdResolved = TRUE;
    }
    return $this->fallbackContactId;
  }

  /**
   * Finds the contact of the record for a save without contact_id.
   *
   * @return int|null
   *   The contact id, or NULL when the record has no contact.
   */
  protected function findFallbackContactId(): ?int {
    try {
      // @todo remove old method when CiviCRM min version > 5.72
      $daoClass = method_exists('CRM_Core_DAO_AllCoreTables', 'getDAONameForEntity')
        ? CRM_Core_DAO_AllCoreTables::getDAONameForEntity($this->getEntity())
        : CRM_Core_DAO_AllCoreTables::getFullName($this->getEntity());
      if ($daoClass && class_exists($daoClass) && array_key_exists('contact_id', $daoClass::fieldKeys())) {
        $dao = new $daoClass();
        $dao->id = (int) $this->getEntityId();
        $dao->selectAdd();
        $dao->selectAdd('id, contact_id');
        if ($dao->find(TRUE)) {
          // The record still exists, so trust its contact, even when empty.
          return is_numeric($dao->contact_id) ? (int) $dao->contact_id : NULL;
        }
      }
    }
    catch (\Throwable $e) {
      return NULL;
    }
    // The record is gone (a delete) or has no contact_id field.
    $contactId = $this->originalData['contact_id'] ?? NULL;
    return (!empty($contactId) && is_numeric($contactId)) ? (int) $contactId : NULL;
  }

}
