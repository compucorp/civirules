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

    if ($this->_action === CRM_Core_Action::ADD) {
      $this->addTypePicker();
      $this->addDelayFields();
    }
  }

  /**
   * Render the delay options below this form's own fields.
   *
   * The delay used to be set on the type picker, which the configuration form now
   * replaces - without this, setting a delay while adding an action would mean
   * saving it and then reopening it via "Edit Delay".
   *
   * @return void
   */
  private function addDelayFields(): void {
    $this->add('select', 'delay_select', E::ts('Delay action to'),
      [' - No Delay - '] + CRM_Civirules_Delay_Factory::getOptionList());
    foreach (CRM_Civirules_Delay_Factory::getAllDelayClasses() as $delayClass) {
      $delayClass->addElements($this, '', $this->rule);
    }
    $this->add('checkbox', 'ignore_condition_with_delay', E::ts("Don't recheck condition upon processing of delayed action"));
    $this->assign('delayClasses', CRM_Civirules_Delay_Factory::getAllDelayClasses());
    $this->assign('delayPrefix', '');
    $this->addFormRule(['CRM_Civirules_Form_RuleAction', 'validateDelay']);
    CRM_Core_Region::instance('form-bottom')->add([
      'template' => 'CRM/Civirules/Form/RuleActionDelay.tpl',
    ]);
  }

  /**
   * Render the action picker above this form's own fields, so choosing a type and
   * configuring it happen in one dialog.
   *
   * Done through the form-top region rather than the per-action templates, none of
   * which need to change.
   *
   * @return void
   */
  private function addTypePicker(): void {
    $options = CRM_Civirules_Form_RuleAction::getActionOptions($this->triggerClass, $this->rule);
    $this->add('select2', 'rule_action_select', E::ts('Action'), $options, TRUE, [
      'class' => 'huge crm-civirules-type-select',
      'data-civirules-type-urls' => json_encode(
        CRM_Civirules_Form_RuleAction::getTypeUrlMap($options, (int) $this->ruleAction->rule_id)
      ),
    ]);
    $this->setDefaults(['rule_action_select' => $this->ruleAction->action_id]);
    CRM_Core_Resources::singleton()->addScriptFile('org.civicoop.civirules', 'js/civirules-add.js');
    CRM_Core_Region::instance('form-top')->add([
      'template' => 'CRM/Civirules/Form/TypePicker.tpl',
      'weight' => -100,
    ]);
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
      $this->applyDelayToRuleAction();
      // Subclasses save conditionally in a few cases, so make sure the row exists.
      // They save before calling us, so a delay chosen here needs saving again.
      if (empty($this->ruleAction->id) || $this->ruleAction->delay !== NULL) {
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
   * Copy the chosen delay onto the rule action, ready to be saved with it.
   *
   * @return void
   */
  private function applyDelayToRuleAction(): void {
    $delayName = $this->_submitValues['delay_select'] ?? NULL;
    if (empty($delayName)) {
      return;
    }
    $delayClass = CRM_Civirules_Delay_Factory::getDelayClassByName($delayName);
    if (!$delayClass) {
      return;
    }
    $delayClass->setValues($this->_submitValues, '', $this->rule);
    $this->ruleAction->delay = serialize($delayClass);
    $this->ruleAction->ignore_condition_with_delay = empty($this->_submitValues['ignore_condition_with_delay']) ? 0 : 1;
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
