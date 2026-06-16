<?php

/**
 * CiviRules Action: Create Portal Access Record.
 *
 * @license AGPL-3.0
 */
class CRM_CivirulesActions_Contact_CreatePortalAccess extends CRM_Civirules_Action {

  /**
   * Writes a Portal Access record for the triggering contact.
   *
   * {@inheritdoc}
   */
  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData): void {
    $contactId = 0;
    try {
      $contactId = $triggerData->getContactId();
      if (empty($contactId)) {
        \Civi::log('civirules')->warning('CiviRules CreatePortalAccess: no contactId in triggerData — skipping.');
        return;
      }

      if ($triggerData instanceof CRM_Civirules_TriggerData_PageView) {
        $url       = $triggerData->getUrl();
        $timestamp = $triggerData->getTimestamp();
        $pageType  = $triggerData->getPageType();
      }
      else {
        $pageViewData = $triggerData->getEntityData('PageView');
        $url          = $pageViewData['url']       ?? '';
        $timestamp    = $pageViewData['timestamp'] ?? date('Y-m-d H:i:s');
        $pageType     = $pageViewData['page_type'] ?? 'Page visit';
      }

      if (empty($url)) {
        return;
      }

      \Civi\Api4\CustomValue::create('Portal_Access', FALSE)
        ->addValue('entity_id', $contactId)
        ->addValue('portal_type', $pageType)
        ->addValue('portal_timestamp', $timestamp)
        ->addValue('portal_url', substr($url, 0, 512))
        ->execute();
    }
    catch (Throwable $e) {
      $message = $e->getMessage();
      \Civi::log('civirules')->error(
        'CiviRules CreatePortalAccess: API error for contact {contactId}: {message}',
        ['contactId' => $contactId, 'message' => $message]
      );
    }
  }

  /**
   * No configuration form is needed — values come entirely from the trigger.
   *
   * {@inheritdoc}
   */
  public function getExtraDataInputUrl($ruleActionId): bool {
    return FALSE;
  }

  /**
   * Summary text shown in the CiviRules rule overview.
   *
   * {@inheritdoc}
   */
  public function userFriendlyConditionParams(): string {
    return ts('Creates a Portal Access record (Type, Timestamp, URL) on the contact.');
  }

}
