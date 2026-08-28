<?php

use CRM_Civirules_ExtensionUtil as E;

class CRM_CivirulesConditions_Form_Form extends CRM_Core_Form {

  protected $ruleConditionId = FALSE;

  /**
   * The (internal) name of the rule condition
   * @var string
   */
  protected $ruleConditionName = '';

  protected CRM_Civirules_BAO_CiviRulesRuleCondition $ruleCondition;

  protected CRM_Civirules_BAO_CiviRulesCondition $condition;

  protected CRM_Civirules_BAO_CiviRulesRule $rule;

  protected CRM_Civirules_BAO_CiviRulesTrigger $trigger;

  /**
   * @var CRM_Civirules_Trigger
   */
  protected $triggerClass;

  /**
   * @var CRM_Civirules_Condition
   */
  protected $conditionClass;

  /**
   * Overridden parent method to perform processing before form is build
   *
   * @access public
   */
  public function preProcess() {
    $this->ruleConditionId = CRM_Utils_Request::retrieve('rule_condition_id', 'Integer') ?: NULL;
    $this->ruleConditionName = CRM_Utils_Request::retrieve('condition_name', 'String');

    $this->ruleCondition = new CRM_Civirules_BAO_CiviRulesRuleCondition();
    $this->condition = new CRM_Civirules_BAO_CiviRulesCondition();
    $this->rule = new CRM_Civirules_BAO_CiviRulesRule();
    $this->trigger = new CRM_Civirules_BAO_CiviRulesTrigger();

    if ($this->ruleConditionId) {
      $this->_action = CRM_Core_Action::UPDATE;
      $this->ruleCondition->id = $this->ruleConditionId;
      if (!$this->ruleCondition->find(TRUE)) {
        throw new Exception('Civirules could not find ruleCondition');
      }
    }
    else {
      // Add mode: populate an unsaved row from the request. Subclasses all end their
      // postProcess() with $this->ruleCondition->save(), which inserts it - so nothing
      // is written if the form is abandoned.
      $this->_action = CRM_Core_Action::ADD;
      $this->ruleCondition->rule_id = CRM_Utils_Request::retrieve('rule_id', 'Integer', NULL, TRUE);
      $this->ruleCondition->condition_id = CRM_Utils_Request::retrieve('condition_id', 'Integer', NULL, TRUE);
      $this->ruleCondition->is_active = 1;
      $conditionLink = CRM_Utils_Request::retrieve('condition_link', 'String');
      if ($conditionLink) {
        // Chosen in the type picker; normalised later by checkAndValidateConditionLinks().
        $this->ruleCondition->condition_link = $conditionLink;
      }
      $this->ruleCondition->weight = CRM_Utils_Weight::getDefaultWeight(
        'CRM_Civirules_DAO_CiviRulesRuleCondition',
        ['rule_id' => $this->ruleCondition->rule_id]
      );
      // Carried in the POST so the form does not depend on the query string
      // surviving submission.
      $this->add('hidden', 'rule_id');
      $this->add('hidden', 'condition_id');
      $this->add('hidden', 'condition_link');
      $this->setDefaults([
        'rule_id' => $this->ruleCondition->rule_id,
        'condition_id' => $this->ruleCondition->condition_id,
        'condition_link' => $this->ruleCondition->condition_link,
      ]);
    }
    $ruleConditionData = [];
    CRM_Core_DAO::storeValues($this->ruleCondition, $ruleConditionData);

    $this->condition->id = $this->ruleCondition->condition_id;
    if (!$this->condition->find(TRUE)) {
      throw new Exception('Civirules could not find condition');
    }

    $this->rule->id = $this->ruleCondition->rule_id;
    if (!$this->rule->find(TRUE)) {
      throw new Exception('Civirules could not find rule');
    }

    $this->trigger->id = $this->rule->trigger_id;
    if (!$this->trigger->find(TRUE)) {
      throw new Exception('Civirules could not find trigger');
    }

    $this->conditionClass = CRM_Civirules_BAO_CiviRulesCondition::getConditionObjectById($this->condition->id, FALSE);
    if ($this->conditionClass) {
      $this->conditionClass->setRuleConditionData($ruleConditionData);
    }

    $this->triggerClass = CRM_Civirules_BAO_CiviRulesTrigger::getTriggerObjectByTriggerId($this->trigger->id, TRUE);
    $this->triggerClass->setTriggerId($this->trigger->id);
    $this->triggerClass->setTriggerParams($this->rule->trigger_params ?? '');

    parent::preProcess();

    $this->setFormTitle();
    $this->assign('ruleConditionHelp', $this->getHelpText());
    $this->assign('ruleName', $this->condition->name);

    //set user context
    $session = CRM_Core_Session::singleton();
    $editUrl = CRM_Utils_System::url('civicrm/civirule/form/rule', 'action=update&id=' . $this->rule->id, TRUE);
    $session->pushUserContext($editUrl);

    if ($this->_action === CRM_Core_Action::ADD) {
      $this->addTypePicker();
    }
  }

  /**
   * Render the condition picker above this form's own fields, so choosing a type and
   * configuring it happen in one dialog.
   *
   * Done through the form-top region rather than the per-condition templates, none of
   * which need to change.
   *
   * @return void
   */
  private function addTypePicker(): void {
    $options = CRM_Civirules_Form_RuleCondition::getConditionOptions($this->triggerClass, $this->rule);
    $this->add('select2', 'rule_condition_select', E::ts('Condition'), $options, TRUE, [
      'class' => 'huge crm-civirules-type-select',
      'data-civirules-type-urls' => json_encode(CRM_Civirules_Form_RuleCondition::getTypeUrlMap(
        $options,
        (int) $this->ruleCondition->rule_id,
        $this->ruleCondition->condition_link
      )),
    ]);
    $this->setDefaults(['rule_condition_select' => $this->ruleCondition->condition_id]);
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
   * @access public
   */
  public function setDefaultValues() {
    $defaultValues = [];
    $defaultValues['rule_condition_id'] = $this->ruleConditionId;
    return $defaultValues;
  }

  public function postProcess() {
    $session = CRM_Core_Session::singleton();
    if ($this->_action === CRM_Core_Action::ADD) {
      // Subclasses save conditionally in a few cases, so make sure the row exists.
      if (empty($this->ruleCondition->id)) {
        $this->ruleCondition->save();
      }
      // DAO::save() does not fire hook_civicrm_post, and condition_link needs
      // normalising for the rule as a whole once a condition is added.
      CRM_Utils_Hook::post('create', 'CiviRulesRuleCondition', $this->ruleCondition->id, $this->ruleCondition);
      $session->setStatus(
        E::ts("Condition '%1' added to CiviRule '%2'", [1 => $this->condition->label, 2 => $this->rule->label]),
        E::ts('Condition added'), 'success');
    }
    else {
      $session->setStatus(E::ts("Condition '%1' parameters updated for CiviRule '%2'", [1 => $this->condition->label, 2 => $this->rule->label]), 'Condition parameters updated', 'success');
    }

    $redirectUrl = CRM_Utils_System::url('civicrm/civirule/form/rule', 'action=update&id=' . $this->rule->id, TRUE);
    $session->pushUserContext($redirectUrl);
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
  protected function saveConditionParams(array $params): void {
    $this->ruleCondition->condition_params = serialize($params);
    $this->ruleCondition->save();
  }

  /**
   * Method to set the form title
   *
   * @access protected
   */
  protected function setFormTitle() {
    $title = 'CiviRules Edit Condition parameters';
    $this->assign('ruleConditionHeader', E::ts("Edit Condition '%1' for CiviRule '%2'", [1 => $this->condition->label, 2 => $this->rule->label]));
    CRM_Utils_System::setTitle($title);
  }

  /**
   * Returns a help text for this condition.
   * The help text is shown to the administrator who is configuring the condition.
   *
   * @return string
   */
  public function getHelpText() {
    return '';
  }

}
