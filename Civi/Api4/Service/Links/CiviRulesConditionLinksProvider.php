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
use Civi\Api4\CiviRulesCondition;

/**
 * @service
 * @internal
 */
class CiviRulesConditionLinksProvider extends \Civi\Core\Service\AutoSubscriber {
  use LinksProviderTrait;

  public static function getSubscribedEvents(): array {
    return [
      'civi.api.respond' => 'alterCiviRulesConditionLinksResult',
    ];
  }

  public static function alterCiviRulesConditionLinksResult(RespondEvent $e): void {
    $request = $e->getApiRequest();
    if ($request['version'] == 4 && $request->getEntityName() === 'CiviRulesRuleCondition' && is_a($request, '\Civi\Api4\Action\GetLinks')) {
      $links = (array) $e->getResponse();
      // $addLinkIndex = self::getActionIndex($links, 'add');
      $editLinkIndex = self::getActionIndex($links, 'update');

      // Expand the "update" link to multiple CiviRule Conditions if it exists (otherwise the WHERE clause excluded it and we should too)
      $where = $request->getWhere();
      $isEditLink = FALSE;
      foreach ($where as $whereValue) {
        if ($whereValue[0] === 'ui_action' && $whereValue[1] === '=' && $whereValue[2] === 'update') {
          $isEditLink = TRUE;
        }
      }
      if ($isEditLink && isset($editLinkIndex)) {
        $ruleID = $request->getValue('rule_id');
        $ruleConditionID = $request->getValue('id');
        $conditionID = $request->getValue('condition_id');
        if ($ruleID) {
          // Ensure links contain exactly the return values requested in the SELECT clause
          $editLink = self::getCiviRulesConditionEditLink($ruleConditionID, $conditionID, $request->getCheckPermissions());
          if ($editLink === NULL) {
            // This condition needs no configuration, or its class is unavailable: offer no edit link.
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
   * @return array|null NULL when this condition has no configuration form to link to.
   */
  private static function getCiviRulesConditionEditLink($ruleConditionID, $conditionID, $checkPermissions): ?array {
    $condition = CiviRulesCondition::get($checkPermissions)
      ->addWhere('id', '=', $conditionID)
      ->execute()
      ->first();

    if (empty($condition['class_name']) || !class_exists($condition['class_name'])) {
      return NULL;
    }
    try {
      $conditionClass = new $condition['class_name']();
      if (!$conditionClass instanceof \CRM_Civirules_Condition) {
        return NULL;
      }
      $path = $conditionClass->getExtraDataInputUrl($ruleConditionID);
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
      'icon' => $condition['icon'] ?? 'fa-plus-square-o',
      'text' => $condition['label'],
    ];
  }

}
