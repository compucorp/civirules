<?php

namespace Civi\Api4\Action\CiviRulesRule;

use Civi\Api4\Generic\BasicBatchAction;

/**
 * Clone one or more CiviRulesRules matched by `where`, including their
 * conditions, actions, and tags. Each clone is created disabled.
 *
 * Used by the "Clone" task on the "Manage Rules" SearchKit screen (see
 * \Civi\Civirules\TasksSubscriber and managed/SavedSearch_CiviRules.mgd.php).
 */
class Copy extends BasicBatchAction {

  /**
   * @param array $item
   * @return array
   */
  protected function doTask($item) {
    $item['clone_id'] = \CRM_Civirules_BAO_CiviRulesRule::cloneRule($item['id']);
    return $item;
  }

}
