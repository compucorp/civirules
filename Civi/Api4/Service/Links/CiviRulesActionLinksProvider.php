<?php

/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Api4\Service\Links;

use Civi\API\Event\RespondEvent;
use Civi\Api4\CiviRulesAction;

/**
 * @service
 * @internal
 */
class CiviRulesActionLinksProvider extends \Civi\Core\Service\AutoSubscriber {
  use LinksProviderTrait;

  public static function getSubscribedEvents(): array {
    return [
      'civi.api.respond' => 'alterCiviRulesActionLinksResult',
    ];
  }

  public static function alterCiviRulesActionLinksResult(RespondEvent $e): void {
    $request = $e->getApiRequest();
    if ($request['version'] == 4 && $request->getEntityName() === 'CiviRulesRuleAction' && is_a($request, '\Civi\Api4\Action\GetLinks')) {
      $links = (array) $e->getResponse();
      // $addLinkIndex = self::getActionIndex($links, 'add');
      $editLinkIndex = self::getActionIndex($links, 'update');

      // Expand the "update" link to multiple CiviRule Actions if it exists (otherwise the WHERE clause excluded it and we should too)
      $where = $request->getWhere();
      $isEditLink = FALSE;
      foreach ($where as $whereValue) {
        if ($whereValue[0] === 'ui_action' && $whereValue[1] === '=' && $whereValue[2] === 'update') {
          $isEditLink = TRUE;
        }
      }
      if ($isEditLink && isset($editLinkIndex)) {
        $ruleID = $request->getValue('rule_id');
        $ruleActionID = $request->getValue('id');
        $actionID = $request->getValue('action_id');
        if ($ruleID) {
          // Ensure links contain exactly the return values requested in the SELECT clause
          $editLink = self::getCiviRulesActionEditLink($ruleActionID, $actionID, $request->getCheckPermissions());
          if ($editLink === NULL) {
            // This action needs no configuration, or its class is unavailable: offer no edit link.
            unset($links[$editLinkIndex]);
          }
          else {
            $links[$editLinkIndex] = array_merge($links[$editLinkIndex], $editLink);
          }
        }
      }
      $e->getResponse()->exchangeArray(array_values($links));
    }
  }

  /**
   * @return array|null NULL when this action has no configuration form to link to.
   */
  private static function getCiviRulesActionEditLink($ruleActionID, $actionID, $checkPermissions): ?array {
    $action = CiviRulesAction::get($checkPermissions)
      ->addWhere('id', '=', $actionID)
      ->execute()
      ->first();

    if (empty($action['class_name']) || !class_exists($action['class_name'])) {
      return NULL;
    }
    try {
      $actionClass = new $action['class_name']();
      if (!$actionClass instanceof \CRM_Civirules_Action) {
        return NULL;
      }
      $path = $actionClass->getExtraDataInputUrl($ruleActionID);
    }
    catch (\Throwable $e) {
      // Instantiating a stale class here is fatal inside a civi.api.respond listener,
      // which would take down the whole SearchDisplay run.
      return NULL;
    }
    if (empty($path)) {
      return NULL;
    }
    return [
      'path' => $path,
      'icon' => $action['icon'] ?? 'fa-plus-square-o',
      'text' => $action['label'],
    ];
  }

}
