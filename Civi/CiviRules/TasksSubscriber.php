<?php

namespace Civi\CiviRules;

use Civi\Core\Service\AutoSubscriber;
use CRM_Civirules_ExtensionUtil as E;

class TasksSubscriber extends AutoSubscriber {

  public static function getSubscribedEvents(): array {
    return [
      '&hook_civicrm_searchKitTasks' => 'hook_civicrm_searchKitTasks',
    ];
  }

  /**
   * Implements hook_civicrm_searchKitTasks().
   *
   * Registers the "Clone" task used by the "Manage Rules" SearchKit screen
   * (see managed/SavedSearch_CiviRules.mgd.php), backed by CiviRulesRule.copy.
   */
  public function hook_civicrm_searchKitTasks(array &$tasks, bool $checkPermissions, ?int $userID): void {
    $tasks['CiviRulesRule']['copy'] = [
      'title' => E::ts('Clone Rule'),
      'icon' => 'fa-copy',
      'apiBatch' => [
        'action' => 'copy',
        'params' => NULL,
        'confirmMsg' => E::ts('Are you sure you want to clone %1 %2?'),
        'runMsg' => E::ts('Cloning %1 %2...'),
        'successMsg' => E::ts('Successfully cloned %1 %2. To view it, change the search filters to view rules that are not enabled.'),
        'errorMsg' => E::ts('An error occurred while attempting to clone %1 %2.'),
      ],
    ];
  }

}
