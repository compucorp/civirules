<?php

use Civi\Api4\CiviRulesAction;
use CRM_Civirules_ExtensionUtil as E;

class CRM_CivirulesActions_Form_Form extends CRM_Core_Form {

  protected $ruleActionId = FALSE;

  protected CRM_Civirules_BAO_CiviRulesRuleAction $ruleAction;

  protected CRM_Civirules_BAO_CiviRulesAction $action;

  protected CRM_Civirules_BAO_CiviRulesRule $rule;

  protected CRM_Civirules_BAO_CiviRulesTrigger $trigger;

  /**
   * @var CRM_Civirules_Trigger
   */
  protected CRM_Civirules_Trigger $triggerClass;

  /**
   * @var CRM_Civirules_Action
   */
  protected CRM_Civirules_Action $actionClass;

  /**
   * Overridden parent method to perform processing before form is build
   */
  public function preProcess() {
    $this->ruleActionId = CRM_Utils_Request::retrieve('rule_action_id', 'Integer') ?: NULL;

    $this->ruleAction = new CRM_Civirules_BAO_CiviRulesRuleAction();
    $this->action = new CRM_Civirules_BAO_CiviRulesAction();
    $this->rule = new CRM_Civirules_BAO_CiviRulesRule();
    $this->trigger = new CRM_Civirules_BAO_CiviRulesTrigger();

    if ($this->ruleActionId) {
      $this->_action = CRM_Core_Action::UPDATE;
      $this->ruleAction->id = $this->ruleActionId;
      if (!$this->ruleAction->find(TRUE)) {
        throw new Exception('Civirules could not find ruleAction (Form)');
      }
    }
    else {
      // Add mode: populate an unsaved row from the request. Subclasses all end their
      // postProcess() with $this->ruleAction->save(), which inserts it - so nothing
      // is written if the form is abandoned.
      $this->_action = CRM_Core_Action::ADD;
      $this->ruleAction->rule_id = CRM_Utils_Request::retrieve('rule_id', 'Integer', NULL, TRUE);
      $this->ruleAction->action_id = CRM_Utils_Request::retrieve('action_id', 'Integer', NULL, TRUE);
      $this->ruleAction->is_active = 1;
      $this->ruleAction->ignore_condition_with_delay = 0;
      $this->ruleAction->weight = CRM_Utils_Weight::getDefaultWeight(
        'CRM_Civirules_DAO_CiviRulesRuleAction',
        ['rule_id' => $this->ruleAction->rule_id]
      );
      // Carried in the POST so the form does not depend on the query string
      // surviving submission.
      $this->add('hidden', 'rule_id');
      $this->add('hidden', 'action_id');
      $this->setDefaults([
        'rule_id' => $this->ruleAction->rule_id,
        'action_id' => $this->ruleAction->action_id,
      ]);
    }

    $this->action->id = $this->ruleAction->action_id;
    if (!$this->action->find(TRUE)) {
      throw new Exception('Civirules could not find action');
    }

    // Instantiate the action class
    $action = CiviRulesAction::get(FALSE)
      ->addSelect('id', 'class_name')
      ->addWhere('id', '=', $this->action->id)
      ->execute()
      ->first();
    if (class_exists($action['class_name'])) {
      $this->actionClass = new $action['class_name']();
    }

    $this->rule->id = $this->ruleAction->rule_id;
    if (!$this->rule->find(TRUE)) {
      throw new Exception('Civirules could not find rule');
    }

    $this->trigger->id = $this->rule->trigger_id;
    if (!$this->trigger->find(TRUE)) {
      throw new Exception('Civirules could not find trigger');
    }

    $this->triggerClass = CRM_Civirules_BAO_CiviRulesTrigger::getPostTriggerObjectByClassName($this->trigger->class_name);
    $this->triggerClass->setTriggerId($this->trigger->id);
    $this->triggerClass->setTriggerParams($this->rule->trigger_params ?? '');

    //set user context
    $session = CRM_Core_Session::singleton();
    $editUrl = CRM_Utils_System::url('civicrm/civirule/form/rule', 'action=update&id=' . $this->rule->id, TRUE);
    $session->pushUserContext($editUrl);

    $this->setFormTitle();

    if (method_exists($this, 'getHelpText')) {
      // Old location, should be moved to main trigger class
      $helpText = $this->getHelpText();
    }
    elseif (method_exists($this->actionClass, 'getHelpText')) {
      // This is the correct location for getHelpText();
      $helpText = $this->actionClass->getHelpText('actionParamsHelp');
    }
    $this->assign('ruleActionHelp', $helpText ?? '');
  }

  /**
   * Overridden parent method to set default values
   *
   * @return array $defaultValues
   */
  public function setDefaultValues() {
    $defaultValues = [];
    $defaultValues['rule_action_id'] = $this->ruleActionId;
    return $defaultValues;
  }

  public function postProcess() {
    if ($this->_action === CRM_Core_Action::ADD) {
      // Subclasses save conditionally in a few cases, so make sure the row exists.
      if (empty($this->ruleAction->id)) {
        $this->ruleAction->save();
      }
      // DAO::save() does not fire hook_civicrm_post.
      CRM_Utils_Hook::post('create', 'CiviRulesRuleAction', $this->ruleAction->id, $this->ruleAction);
      CRM_Core_Session::setStatus(
        E::ts("Action '%1' added to CiviRule '%2'", [1 => $this->action->label, 2 => $this->rule->label]),
        E::ts('Action added'),
        'success'
      );
      return;
    }
    CRM_Core_Session::setStatus(E::ts("Action '%1' parameters updated for CiviRule '%2'", [1 => $this->action->label, 2 => $this->rule->label]),
      E::ts('Action parameters updated'),
      'success'
    );
  }

  /**
   * Store configuration parameters and, in add mode, create the row.
   *
   * Subclasses currently assign to the DAO and call save() directly; this is the
   * supported way to do it.
   *
   * @param array $params
   *
   * @return void
   */
  protected function saveActionParams(array $params): void {
    $this->ruleAction->action_params = serialize($params);
    $this->ruleAction->save();
  }

  /**
   * Method to set the form title
   *
   * @access protected
   */
  protected function setFormTitle() {
    $title = 'CiviRules Edit Action parameters';
    $this->assign('ruleActionHeader', E::ts("Edit Action '%1' for CiviRule '%2'", [
      1 => $this->action->label,
      2 => $this->rule->label,
    ]));

    CRM_Utils_System::setTitle($title);
  }

}
