<?php

/**
 * TriggerData for the PageView trigger.
 *
 * @license AGPL-3.0
 */
class CRM_Civirules_TriggerData_PageView extends CRM_Civirules_TriggerData_TriggerData {

  /**
   * @param int    $contactId  CiviCRM contact ID of the logged-in user.
   * @param string $url        Full request URI, e.g. /civicrm/event/info?id=42
   */
  public function __construct(int $contactId, string $url) {
    parent::__construct();

    $this->setContactId($contactId);
    $this->setEntityId($contactId);
    $this->setEntity('Contact');
    $this->setEntityData('Contact', ['id' => $contactId]);
    $this->setEntityData('PageView', ['url' => $url, 'page_type' => 'Page visit', 'timestamp' => date('Y-m-d H:i:s')]);
  }

  /**
   * Returns the primary entity name.
   *
   * @return string
   */
  public function getEntity(): string {
    return 'Contact';
  }

  /**
   * Convenience getter for the visited URL.
   *
   * @return string
   */
  public function getUrl(): string {
    $data = $this->getEntityData('PageView');
    return $data['url'] ?? '';
  }

  /**
   * Convenience getter for the visit timestamp (ISO Y-m-d H:i:s format).
   *
   * @return string
   */
  public function getTimestamp(): string {
    $data = $this->getEntityData('PageView');
    return $data['timestamp'] ?? date('Y-m-d H:i:s');
  }

  /**
   * Convenience getter for the page type label.
   *
   * @return string
   */
  public function getPageType(): string {
    $data = $this->getEntityData('PageView');
    return $data['page_type'] ?? 'Page visit';
  }

}
