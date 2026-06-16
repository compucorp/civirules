<?php

/**
 * CiviRules Trigger: User accesses a site page.
 *
 * @license AGPL-3.0
 */
class CRM_CivirulesTrigger_PageView extends CRM_Civirules_Trigger {

  /**
   * Returns the entity this trigger reacts on (Contact).
   *
   * @return CRM_Civirules_TriggerData_EntityDefinition
   */
  protected function reactOnEntity(): CRM_Civirules_TriggerData_EntityDefinition {
    return new CRM_Civirules_TriggerData_EntityDefinition(
      'Contact',
      'Contact',
      'CRM_Contact_DAO_Contact',
      'Contact'
    );
  }

  /**
   * Returns the TriggerData class name used by this trigger.
   *
   * @return string
   */
  public function getTriggerDataClassName(): string {
    return 'CRM_Civirules_TriggerData_PageView';
  }

  /**
   * Human-readable description shown in the CiviRules rule UI.
   *
   * @return string
   */
  public function getTriggerDescription(): string {
    return ts('Fired when a logged-in user visits a page.');
  }

  /**
   * Fire all active CiviRules rules that use this trigger for the given contact and URL.
   *
   * @param int    $contactId  CiviCRM contact ID of the logged-in user.
   * @param string $url        Full request URI.
   */
  public static function fireForContact(int $contactId, string $url): void {
    $rules = CRM_Civirules_BAO_CiviRulesRule::findRulesByClassname('CRM_CivirulesTrigger_PageView');
    if (empty($rules)) {
      return;
    }

    foreach ($rules as $triggerObject) {
      $triggerData = new CRM_Civirules_TriggerData_PageView($contactId, $url);
      try {
        CRM_Civirules_Engine::triggerRule($triggerObject, $triggerData);
      }
      catch (Throwable $e) {
        \Civi::log('civirules')->error(
          'CiviRules PageView trigger failed for contact {contactId} on {url}: {message}',
          [
            'contactId' => $contactId,
            'url'       => $url,
            'message'   => $e->getMessage(),
          ]
        );
      }
    }
  }

}
