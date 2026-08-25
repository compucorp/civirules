<?php
/**
 * Form controller class to manage CiviRule/RuleCondition
 *
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC43/QuickForm+Reference
 *
 * @author Erik Hommel (CiviCooP) <erik.hommel@civicoop.org>
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */

use CRM_Civirules_ExtensionUtil as E;
use Civi\Api4\CiviRulesCondition;
use Civi\Api4\CiviRulesRuleCondition;

class CRM_Civirules_Form_RuleCondition extends CRM_Core_Form {

  /**
   * @var \CRM_Civirules_BAO_CiviRulesRule
   */
  protected \CRM_Civirules_BAO_CiviRulesRule $rule;

  /**
   * @var \CRM_Civirules_BAO_CiviRulesTrigger
   */
  protected \CRM_Civirules_BAO_CiviRulesTrigger $trigger;

  /**
   * @var \CRM_Civirules_Trigger
   */
  protected \CRM_Civirules_Trigger $triggerObject;

  /**
   * @var int
   */
  protected ?int $ruleId = NULL;

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
    $this->ruleId = CRM_Utils_Request::retrieve('rid', 'Integer');
    $redirectUrl = CRM_Utils_System::url('civicrm/civirule/form/rule', 'action=update&id=' . $this->ruleId, TRUE);
    $session = CRM_Core_Session::singleton();
    $session->pushUserContext($redirectUrl);
    $this->assign('countRuleConditions', CRM_Civirules_BAO_RuleCondition::countConditionsForRule($this->ruleId));
    if ($this->_action == CRM_Core_Action::DELETE) {
      $ruleConditionId = CRM_Utils_Request::retrieve('id', 'Integer');
      if (!empty($ruleConditionId)) {
        CiviRulesRuleCondition::delete(FALSE)
          ->addWhere('id', '=', $ruleConditionId)
          ->execute();
      }
      CRM_Utils_System::redirect($redirectUrl);
      return;
    }

    $this->rule = new CRM_Civirules_BAO_CiviRulesRule();
    $this->rule->id = $this->ruleId;
    $this->rule->find(TRUE);
    $this->trigger = new CRM_Civirules_BAO_Trigger();
    $this->trigger->id = $this->rule->trigger_id;
    $this->trigger->find(TRUE);

    $this->triggerObject = CRM_Civirules_BAO_Trigger::getPostTriggerObjectByClassName($this->trigger->class_name, TRUE);
    $this->triggerObject->setTriggerId($this->trigger->id);
  }

  /**
   * Select2 options for the conditions available on a given trigger.
   *
   * Shared with the configuration forms, which render the same picker so the type can
   * be changed without leaving the dialog.
   *
   * @param CRM_Civirules_Trigger $trigger
   * @param CRM_Civirules_BAO_CiviRulesRule $rule
   *
   * @return array
   */
  public static function getConditionOptions(CRM_Civirules_Trigger $trigger, CRM_Civirules_BAO_CiviRulesRule $rule): array {
    $options = [];
    $conditions = CiviRulesCondition::get(FALSE)
      ->addSelect('id', 'label')
      ->addOrderBy('label', 'ASC')
      ->execute()
      ->indexBy('id')
      ->column('label');
    foreach ($conditions as $conditionId => $label) {
      $conditionClass = CRM_Civirules_BAO_CiviRulesCondition::getConditionObjectById($conditionId, FALSE);
      if (!$conditionClass) {
        continue;
      }
      try {
        // Some implementations dereference trigger data that this trigger does not
        // provide; treat that as "not available" rather than letting it escape.
        if (!$conditionClass->doesWorkWithTrigger($trigger, $rule)) {
          continue;
        }
      }
      catch (Throwable $e) {
        continue;
      }
      $options[] = ['id' => $conditionId, 'text' => $label];
    }
    return $options;
  }

  /**
   * Map of condition id => url to swap the dialog to when that type is picked.
   *
   * Conditions with no configuration form of their own map back to the picker with the
   * type preselected, so every option leads somewhere sensible.
   *
   * @param array $options
   * @param int $ruleId
   * @param string|null $conditionLink
   *
   * @return array
   */
  public static function getTypeUrlMap(array $options, int $ruleId, ?string $conditionLink = NULL): array {
    $urls = [];
    foreach ($options as $option) {
      $urls[$option['id']] = self::getAddConfigUrl((int) $option['id'], $ruleId, $conditionLink)
        ?: CRM_Utils_System::url(
          'civicrm/civirule/form/rule_condition',
          ['reset' => 1, 'action' => 'add', 'rule_id' => $ruleId, 'preselect' => $option['id']],
          FALSE, NULL, FALSE, FALSE, TRUE
        );
    }
    return $urls;
  }

  /**
   * URL of a condition's configuration form, addressed by type rather than by row.
   *
   * Returns NULL when the condition needs no configuration, or when its class predates
   * getConfigFormPath() - in both cases the caller creates the row itself, which for
   * the latter preserves the old two-step behaviour.
   *
   * @param int $conditionId
   * @param int $ruleId
   * @param string|null $conditionLink
   *
   * @return string|null
   */
  public static function getAddConfigUrl(int $conditionId, int $ruleId, ?string $conditionLink = NULL): ?string {
    $conditionClass = CRM_Civirules_BAO_CiviRulesCondition::getConditionObjectById($conditionId, FALSE);
    if (!$conditionClass) {
      return NULL;
    }
    $path = $conditionClass->getConfigFormPath();
    if (!$path) {
      if (!empty($conditionClass->getExtraDataInputUrl(0))) {
        CRM_Core_Error::deprecatedWarning(
          'CiviRules: ' . get_class($conditionClass) . ' builds its configuration form url by hand; '
          . 'implement getConfigFormPath() so it can be added in one step.'
        );
      }
      return NULL;
    }
    $params = $conditionClass->getConfigFormParams() + ['rule_id' => $ruleId, 'condition_id' => $conditionId];
    if ($conditionLink !== NULL && $conditionLink !== '') {
      $params['condition_link'] = $conditionLink;
    }
    return CRM_Utils_System::url($path, $params, FALSE, NULL, FALSE, FALSE, TRUE);
  }

  /**
   * Function to perform post save processing (extends parent function)
   *
   * @access public
   */
  public function postProcess() {
    $session = CRM_Core_Session::singleton();

    // Adding a condition that has a configuration form: hand straight over to it and
    // let it create the row, so cancelling there leaves nothing behind.
    if (!empty($this->_submitValues['rule_condition_select'])) {
      $conditionId = (int) $this->_submitValues['rule_condition_select'];
      $configUrl = self::getAddConfigUrl(
        $conditionId,
        (int) $this->_submitValues['rule_id'],
        $this->_submitValues['rule_condition_link_select'] ?? NULL
      );
      if ($configUrl) {
        CRM_Utils_System::redirect($configUrl);
      }
    }

    $saveParams = [
      'rule_id' => $this->_submitValues['rule_id'],
      'condition_id' => $this->_submitValues['rule_condition_select'],
    ];
    if (isset($this->_submitValues['rule_condition_link_select'])) {
      $saveParams['condition_link'] = $this->_submitValues['rule_condition_link_select'];
    }

    // Use save not writeRecord to make sure "weight" is set correctly
    $ruleCondition = CiviRulesRuleCondition::save(FALSE)
      ->setRecords([$saveParams])
      ->execute()
      ->first();

    $condition = CRM_Civirules_BAO_Condition::getConditionObjectById($ruleCondition['condition_id'], TRUE);
    $redirectUrl = $condition->getExtraDataInputUrl($ruleCondition['id']);
    if (empty($redirectUrl)) {
      $redirectUrl = CRM_Utils_System::url('civicrm/civirule/form/rule', 'action=update&id=' . $this->_submitValues['rule_id'], TRUE);
      if (empty($this->ruleConditionId)) {
        $session->setStatus('Condition added to CiviRule ' . CRM_Civirules_BAO_CiviRulesRule::getRuleLabelWithId($this->_submitValues['rule_id']),
          'Condition added', 'success');
      }
    }
    else {
      // Redirect to action configuration (required to redirect popup without closing
      CRM_Utils_System::redirect($redirectUrl);
    }

    // This will allow popup to close
    $session->pushUserContext($redirectUrl);
  }

  protected function buildConditionList() {
    $conditions = CiviRulesCondition::get(FALSE)
      ->addOrderBy('label', 'ASC')
      ->execute()
      ->indexBy('id')
      ->column('label');
    foreach ($conditions as $conditionID => $conditionLabel) {
      if ($this->doesConditionWorkWithTrigger($conditionID)) {
        $conditionOptions[$conditionID] = $conditionLabel;
      }
    }
    return $conditionOptions ?? [];
  }

  /**
   * Returns whether the condition works with the trigger
   *
   * @param int $conditionID
   *
   * @return bool
   */
  protected function doesConditionWorkWithTrigger(int $conditionID): bool {
    try {
      $conditionClass = CRM_Civirules_BAO_Condition::getConditionObjectById($conditionID, FALSE);
      if (!$conditionClass) {
        return FALSE;
      }
    }
    catch (Exception $e) {
      return FALSE;
    }
    if (!$conditionClass->doesWorkWithTrigger($this->triggerObject, $this->rule)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Function to add the form elements
   *
   * @access protected
   */
  protected function createFormElements() {
    $this->add('hidden', 'rule_id');
    $this->add('hidden', 'rid');
    $this->add('hidden', 'action');
    /*
     * add select list only if it is not the first condition
     */
    $linkList = CRM_Civirules_BAO_CiviRulesRuleCondition::getConditionLinkOptions();
    $this->add('select', 'rule_condition_link_select', E::ts('Select Link Operator'), $linkList);
    $foundConditions = $this->buildConditionList();
    if (!empty($foundConditions)) {
      $conditionList = [' - select - '] + $foundConditions;
      asort($conditionList);
    }
    else {
      $conditionList = [' - select - '];
    }
    $conditionOptions = self::getConditionOptions($this->triggerObject, $this->rule);
    $this->add('select', 'rule_condition_select', E::ts('Select Condition'), $conditionList, TRUE, [
      'class' => 'crm-select2 huge crm-civirules-type-select',
      'data-civirules-type-urls' => json_encode(self::getTypeUrlMap($conditionOptions, (int) $this->ruleId)),
    ]);
    $preselect = CRM_Utils_Request::retrieve('preselect', 'Integer');
    if ($preselect) {
      $this->setDefaults(['rule_condition_select' => $preselect]);
    }
    CRM_Core_Resources::singleton()->addScriptFile('org.civicoop.civirules', 'js/civirules-add.js');

    $this->addButtons([
      ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  public function setDefaultValues() {
    $defaults['rule_id'] = $this->ruleId;
    $defaults['rid'] = $this->ruleId;
    $defaults['action'] = 'add';
    return $defaults;
  }

  /**
   * Function to set the form title based on action and data coming in
   *
   * @access protected
   */
  protected function setFormTitle() {
    $title = 'CiviRules Add Condition';
    $this->assign('ruleConditionHeader', 'Add Condition to CiviRule ' . CRM_Civirules_BAO_CiviRulesRule::getRuleLabelWithId($this->ruleId));
    CRM_Utils_System::setTitle($title);
  }

  /**
   * Function to add validation condition rules (overrides parent function)
   *
   * @access public
   */
  public function addRules() {
    $this->addFormRule(['CRM_Civirules_Form_RuleCondition', 'validateRuleCondition']);
    $this->addFormRule(['CRM_Civirules_Form_RuleCondition', 'validateConditionEntities']);
  }

  /**
   * @param $fields
   * @return array|bool
   */
  public static function validateConditionEntities($fields) {
    $conditionClass = CRM_Civirules_BAO_Condition::getConditionObjectById($fields['rule_condition_select'], FALSE);
    if (!$conditionClass) {
      $errors['rule_condition_select'] = E::ts('Not a valid condition, condition class is missing');
      return $errors;
    }

    $rule = new CRM_Civirules_BAO_CiviRulesRule();
    $rule->id = $fields['rule_id'];
    $rule->find(TRUE);
    $trigger = new CRM_Civirules_BAO_Trigger();
    $trigger->id = $rule->trigger_id;
    $trigger->find(TRUE);

    $triggerObject = CRM_Civirules_BAO_Trigger::getPostTriggerObjectByClassName($trigger->class_name, TRUE);
    $triggerObject->setTriggerId($trigger->id);

    if (!$conditionClass->doesWorkWithTrigger($triggerObject, $rule)) {
      $errors['rule_condition_select'] = E::ts('This condition is not available with trigger %1', [1 => $trigger->label]);
      return $errors;
    }

    return TRUE;
  }

  /**
   * Function to validate value of rule condition form
   *
   * @param array $fields
   * @return array|bool
   * @access public
   * @static
   */
  public static function validateRuleCondition($fields) {
    if (isset($fields['rule_condition_link_select']) && empty($fields['rule_condition_link_select'])) {
      $errors['rule_condition_link_select'] = E::ts('Link Operator can only be AND or OR');
      return $errors;
    }
    if (isset($fields['rule_condition_select']) && empty($fields['rule_condition_select'])) {
      $errors['rule_condition_select'] = E::ts('Condition has to be selected, press CANCEL if you do not want to add a condition');
      return $errors;
    }
    return TRUE;
  }

}
