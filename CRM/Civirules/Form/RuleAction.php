<?php
/**
 * Form controller class to manage CiviRule/RuleAction
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */

use CRM_Civirules_ExtensionUtil as E;
use Civi\Api4\CiviRulesAction;
use Civi\Api4\CiviRulesRuleAction;

class CRM_Civirules_Form_RuleAction extends CRM_Core_Form {

  protected $ruleId = NULL;

  protected $ruleActionId;

  protected CRM_Civirules_BAO_CiviRulesRuleAction $ruleAction;

  protected CRM_Civirules_BAO_CiviRulesAction $action;

  protected CRM_Civirules_BAO_CiviRulesRule $rule;

  /**
   * @var \CRM_Civirules_Trigger
   */
  protected \CRM_Civirules_Trigger $triggerObject;

  /**
   * If TRUE, only edit the delay params
   * @var bool
   */
  protected bool $editDelay = FALSE;

  /**
   * Function to buildQuickForm (extends parent function)
   *
   * @access public
   */
  public function buildQuickForm() {
    $this->setFormTitle();
    $this->createFormElements();
    parent::buildQuickForm();
  }

  /**
   * Function to perform processing before displaying form (overrides parent function)
   *
   * @access public
   */
  public function preProcess() {
    $this->ruleId = CRM_Utils_Request::retrieve('rule_id', 'Integer');
    $this->ruleActionId = CRM_Utils_Request::retrieve('id', 'Integer');
    $this->editDelay = CRM_Utils_Request::retrieveValue('editdelay', 'Boolean') ?? FALSE;

    if (!$this->ruleId && $this->getAction()) {
      CRM_Core_Error::statusBounce('Missing rule ID');
    }
    if (!$this->ruleActionId && $this->getAction() !== CRM_Core_Action::ADD) {
      CRM_Core_Error::statusBounce('Missing RuleAction ID');
    }

    $this->rule = new CRM_Civirules_BAO_CiviRulesRule();
    $this->rule->id = $this->ruleId;
    $this->rule->find(TRUE);

    $trigger = new CRM_Civirules_BAO_CiviRulesTrigger();
    $trigger->id = $this->rule->trigger_id;
    $trigger->find(TRUE);
    $this->triggerObject = CRM_Civirules_BAO_CiviRulesTrigger::getPostTriggerObjectByClassName($trigger->class_name, TRUE);
    $this->triggerObject->setTriggerId($trigger->id);

    if ($this->ruleActionId) {
      $this->ruleAction = new CRM_Civirules_BAO_CiviRulesRuleAction();
      $this->ruleAction->id = $this->ruleActionId;
      if (!$this->ruleAction->find(TRUE)) {
        throw new Exception('Civirules could not find ruleAction (RuleAction)');
      }

      $this->action = new CRM_Civirules_BAO_CiviRulesAction();
      $this->action->id = $this->ruleAction->action_id;
      if (!$this->action->find(TRUE)) {
        throw new Exception('Civirules could not find action');
      }

      $this->assign('action_label', $this->action->label);
    }
  }

  /**
   * Function to perform post save processing (extends parent function)
   *
   * @access public
   */
  public function postProcess() {
    // Adding an action that has a configuration form: hand straight over to it and
    // let it create the row, so cancelling there leaves nothing behind.
    if (!$this->ruleActionId && !empty($this->getSubmittedValue('rule_action_select'))) {
      $actionId = (int) $this->getSubmittedValue('rule_action_select');
      $configUrl = self::getAddConfigUrl($actionId, (int) $this->getSubmittedValue('rule_id'));
      if ($configUrl) {
        CRM_Utils_System::redirect($configUrl);
      }
    }

    $saveParams = [];
    $saveParams['rule_id'] = $this->getSubmittedValue('rule_id');
    $saveParams['delay'] = NULL;
    $saveParams['ignore_condition_with_delay'] = '0';
    if (!empty($this->getSubmittedValue('rule_action_select'))) {
      if (!isset($this->ruleAction)) {
        $this->ruleAction = new CRM_Civirules_BAO_CiviRulesRuleAction();
      }
      $this->ruleAction->action_id = $this->getSubmittedValue('rule_action_select');
      $saveParams['action_id'] = $this->getSubmittedValue('rule_action_select');
    }
    if ($this->ruleActionId) {
      $saveParams['id'] = $this->ruleActionId;
    }

    if (!empty($this->_submitValues['delay_select'])) {
      $delayClass = CRM_Civirules_Delay_Factory::getDelayClassByName($this->_submitValues['delay_select']);
      $delayClass->setValues($this->_submitValues, '', $this->rule);
      $saveParams['delay'] = serialize($delayClass);
      if (!empty($this->_submitValues['ignore_condition_with_delay'])) {
        $saveParams['ignore_condition_with_delay'] = '1';
      }
    }

    $ruleAction = CiviRulesRuleAction::save(FALSE)
      ->setRecords([$saveParams])
      ->execute()
      ->first();

    $session = CRM_Core_Session::singleton();
    $action = CRM_Civirules_BAO_CiviRulesAction::getActionObjectById($this->ruleAction->action_id, TRUE);
    $redirectUrl = $action->getExtraDataInputUrl($ruleAction['id']);
    if (empty($redirectUrl) || $this->editDelay) {
      $redirectUrl = CRM_Utils_System::url('civicrm/civirule/form/rule', 'action=update&id=' . $this->getSubmittedValue('rule_id'), TRUE);
      if (empty($this->ruleActionId)) {
        $session->setStatus('Action added to CiviRule ' . CRM_Civirules_BAO_CiviRulesRule::getRuleLabelWithId($this->getSubmittedValue('rule_id')),
          'Action added', 'success');
      }
    }
    else {
      // Redirect to action configuration (required to redirect popup without closing
      CRM_Utils_System::redirect($redirectUrl);
    }

    // This will allow popup to close
    $session->pushUserContext($redirectUrl);
  }

  /**
   * Returns whether the action works with the trigger attached to this rule
   *
   * @param int $actionID
   *
   * @return bool
   */
  protected function doesActionWorkWithTrigger(int $actionID): bool {
    try {
      $actionClass = CRM_Civirules_BAO_CiviRulesAction::getActionObjectById($actionID, FALSE);
      if (!$actionClass) {
        return FALSE;
      }
    }
    catch (Exception $e) {
      return FALSE;
    }
    return $actionClass->doesWorkWithTrigger($this->triggerObject, $this->rule);
  }

  /**
   * Select2 options for the actions available on a given trigger.
   *
   * Shared with the configuration forms, which render the same picker so the type can
   * be changed without leaving the dialog.
   *
   * @param CRM_Civirules_Trigger $trigger
   * @param CRM_Civirules_BAO_CiviRulesRule $rule
   *
   * @return array
   */
  public static function getActionOptions(CRM_Civirules_Trigger $trigger, CRM_Civirules_BAO_CiviRulesRule $rule): array {
    $options = [];
    $actionList = CiviRulesAction::get(FALSE)
      ->addSelect('id', 'label', 'name', 'class_name')
      ->addOrderBy('label', 'ASC')
      ->addWhere('is_active', '=', TRUE)
      ->execute()
      ->indexBy('id');
    foreach ($actionList as $id => $detail) {
      $actionClass = CRM_Civirules_BAO_CiviRulesAction::getActionObjectById($id, FALSE);
      if (!$actionClass) {
        continue;
      }
      try {
        // Some implementations dereference trigger data that this trigger does not
        // provide; treat that as "not available" rather than letting it escape.
        if (!$actionClass->doesWorkWithTrigger($trigger, $rule)) {
          continue;
        }
      }
      catch (Throwable $e) {
        continue;
      }
      $description = '';
      try {
        $description = $actionClass->getHelpText('actionDescription');
      }
      catch (Throwable $e) {
        // Do nothing, we'll continue without description
      }
      $options[] = [
        'id' => $id,
        'text' => $detail['label'],
        'description' => $description,
      ];
    }
    return $options;
  }

  /**
   * Map of action id => url to swap the dialog to when that type is picked.
   *
   * Actions with no configuration form of their own map back to the picker with the
   * type preselected, so every option leads somewhere sensible.
   *
   * @param array $options
   * @param int $ruleId
   *
   * @return array
   */
  public static function getTypeUrlMap(array $options, int $ruleId): array {
    $urls = [];
    foreach ($options as $option) {
      $urls[$option['id']] = self::getAddConfigUrl((int) $option['id'], $ruleId)
        ?: CRM_Utils_System::url(
          'civicrm/civirule/form/rule_action',
          ['reset' => 1, 'action' => 'add', 'rule_id' => $ruleId, 'preselect' => $option['id']],
          FALSE, NULL, FALSE, FALSE, TRUE
        );
    }
    return $urls;
  }

  /**
   * URL of an action's configuration form, addressed by type rather than by row.
   *
   * Returns NULL when the action needs no configuration, or when its class predates
   * getConfigFormPath() - in both cases the caller creates the row itself, which for
   * the latter preserves the old two-step behaviour.
   *
   * @param int $actionId
   * @param int $ruleId
   *
   * @return string|null
   */
  public static function getAddConfigUrl(int $actionId, int $ruleId): ?string {
    $actionClass = CRM_Civirules_BAO_CiviRulesAction::getActionObjectById($actionId, FALSE);
    if (!$actionClass) {
      return NULL;
    }
    $path = $actionClass->getConfigFormPath();
    if (!$path) {
      if (!empty($actionClass->getExtraDataInputUrl(0))) {
        CRM_Core_Error::deprecatedWarning(
          'CiviRules: ' . get_class($actionClass) . ' builds its configuration form url by hand; '
          . 'implement getConfigFormPath() so it can be added in one step.'
        );
      }
      return NULL;
    }
    $params = $actionClass->getConfigFormParams() + ['rule_id' => $ruleId, 'action_id' => $actionId];
    return CRM_Utils_System::url($path, $params, FALSE, NULL, FALSE, FALSE, TRUE);
  }

  /**
   * Function to add the form elements
   *
   * @access protected
   */
  protected function createFormElements() {
    $this->add('hidden', 'rule_id');
    if ($this->ruleActionId) {
      $this->add('hidden', 'id');
    }

    $actions = self::getActionOptions($this->triggerObject, $this->rule);

    if ($this->getAction() === CRM_Core_Action::ADD) {
      $this->add('select2', 'rule_action_select', E::ts('Select Action'), $actions, TRUE, [
        'class' => 'huge crm-civirules-type-select',
        'data-civirules-type-urls' => json_encode(self::getTypeUrlMap($actions, (int) $this->ruleId)),
      ]);
      $preselect = CRM_Utils_Request::retrieve('preselect', 'Integer');
      if ($preselect) {
        $this->setDefaults(['rule_action_select' => $preselect]);
      }
      CRM_Core_Resources::singleton()->addScriptFile('org.civicoop.civirules', 'js/civirules-add.js');
    }

    $delayList = [' - No Delay - '] + CRM_Civirules_Delay_Factory::getOptionList();
    $this->add('select', 'delay_select', E::ts('Delay action to'), $delayList);
    foreach (CRM_Civirules_Delay_Factory::getAllDelayClasses() as $delay_class) {
      $delay_class->addElements($this, '', $this->rule);
    }
    $this->assign('delayClasses', CRM_Civirules_Delay_Factory::getAllDelayClasses());
    $this->assign('delayPrefix', '');
    $this->add('checkbox', 'ignore_condition_with_delay', E::ts("Don't recheck condition upon processing of delayed action"));

    $this->addButtons([
      ['type' => 'next', 'name' => E::ts('Next'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  public function setDefaultValues() {
    $defaults['rule_id'] = $this->ruleId;

    foreach (CRM_Civirules_Delay_Factory::getAllDelayClasses() as $delay_class) {
      $delay_class->setDefaultValues($defaults, '', $this->rule);
    }

    if (!empty($this->ruleActionId)) {
      $defaults['rule_action_select'] = $this->ruleAction->action_id;
      $defaults['id'] = $this->ruleActionId;
      if (isset($this->ruleAction->ignore_condition_with_delay)) {
        $defaults['ignore_condition_with_delay'] = $this->ruleAction->ignore_condition_with_delay;
      }

      if (!empty($this->ruleAction->delay)) {
        // Deprecated compatibility check - remove once all data migrated to array storage
        $delayClass = is_array($this->ruleAction->delay) ? $this->ruleAction->delay : unserialize($this->ruleAction->delay);
      }
      if (isset($delayClass)) {
        $defaults['delay_select'] = get_class($delayClass);
        foreach ($delayClass->getValues('', $this->rule) as $key => $val) {
          $defaults[$key] = $val;
        }
      }

    }

    return $defaults;
  }

  /**
   * Function to set the form title based on action and data coming in
   *
   * @access protected
   */
  protected function setFormTitle() {
    $title = 'CiviRules Add Action';
    $this->assign('ruleActionHeader', 'Add Action to CiviRule ' . CRM_Civirules_BAO_CiviRulesRule::getRuleLabelWithId($this->ruleId));
    CRM_Utils_System::setTitle($title);
  }

  /**
   * Function to add validation action rules (overrides parent function)
   *
   * @access public
   */
  public function addRules() {
    if (empty($this->ruleActionId)) {
      $this->addFormRule([
        'CRM_Civirules_Form_RuleAction',
        'validateRuleAction',
      ]);
    }
    $this->addFormRule([
      'CRM_Civirules_Form_RuleAction',
      'validateDelay',
    ]);
  }

  /**
   * Function to validate value of rule action form
   *
   * @param array $fields
   * @return array|bool
   * @access public
   * @static
   */
  public static function validateRuleAction($fields) {
    $errors = [];
    if (isset($fields['rule_action_select']) && empty($fields['rule_action_select'])) {
      $errors['rule_action_select'] = E::ts('Action has to be selected, press CANCEL if you do not want to add an action');
    }
    else {
      $actionClass = CRM_Civirules_BAO_CiviRulesAction::getActionObjectById($fields['rule_action_select'], FALSE);
      if (!$actionClass) {
        $errors['rule_action_select'] = E::ts('Not a valid action, action class is missing');
      }
      else {
        $rule = new CRM_Civirules_BAO_CiviRulesRule();
        $rule->id = $fields['rule_id'];
        $rule->find(TRUE);
        $trigger = new CRM_Civirules_BAO_CiviRulesTrigger();
        $trigger->id = $rule->trigger_id;
        $trigger->find(TRUE);

        $triggerObject = CRM_Civirules_BAO_CiviRulesTrigger::getPostTriggerObjectByClassName($trigger->class_name, TRUE);
        $triggerObject->setTriggerId($trigger->id);
        if (!$actionClass->doesWorkWithTrigger($triggerObject, $rule)) {
          $errors['rule_action_select'] = E::ts('This action is not available with trigger %1', [1 => $trigger->label]);
        }
      }
    }

    if (count($errors)) {
      return $errors;
    }

    return TRUE;
  }

  /**
   * Function to validate value of the delay
   *
   * @param array $fields
   * @return array|bool
   * @access public
   * @static
   */
  public static function validateDelay($fields) {
    $errors = [];
    if (!empty($fields['delay_select'])) {
      // When adding, the rule action does not exist yet, so the rule is identified
      // directly rather than looked up through it.
      $ruleId = $fields['rule_id'] ?? NULL;
      if (empty($ruleId)) {
        $ruleAction = new CRM_Civirules_BAO_CiviRulesRuleAction();
        $ruleAction->id = CRM_Utils_Request::retrieve('rule_action_id', 'Integer');
        $ruleAction->find(TRUE);
        $ruleId = $ruleAction->rule_id;
      }
      $rule = new CRM_Civirules_BAO_CiviRulesRule();
      $rule->id = $ruleId;
      $rule->find(TRUE);

      $delayClass = CRM_Civirules_Delay_Factory::getDelayClassByName($fields['delay_select']);
      $delayClass->validate($fields, $errors, '', $rule);
    }

    if (count($errors)) {
      return $errors;
    }

    return TRUE;
  }

}
