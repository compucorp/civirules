<?php

/**
 * Form for the "URL Contains" CiviRules condition.
 *
 * @license AGPL-3.0
 */
class CRM_CivirulesConditions_Form_PageView_UrlContains extends CRM_CivirulesConditions_Form_Form {

  /**
   * {@inheritdoc}
   */
  public function buildQuickForm() {
    $this->add('hidden', 'rule_condition_id');

    $this->add(
      'text',
      'url_pattern',
      ts('URL Pattern'),
      [
        'size'        => 80,
        'maxlength'   => 255,
        'placeholder' => ts('e.g. civicrm/event/* or event/my-event'),
      ],
      TRUE
    );

    $this->addButtons([
      ['type' => 'next',   'name' => ts('Save'),   'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => ts('Cancel')],
    ]);

    parent::buildQuickForm();
  }

  /**
   * {@inheritdoc}
   */
  public function setDefaultValues(): array {
    $defaultValues = parent::setDefaultValues();
    $data = [];
    if (!empty($this->ruleCondition->condition_params)) {
      $data = unserialize($this->ruleCondition->condition_params);
    }
    if (!empty($data['url_pattern'])) {
      $defaultValues['url_pattern'] = $data['url_pattern'];
    }
    return $defaultValues;
  }

  /**
   * {@inheritdoc}
   */
  public function postProcess() {
    $data['url_pattern'] = trim($this->_submitValues['url_pattern']);
    $this->ruleCondition->condition_params = serialize($data);
    $this->ruleCondition->save();

    parent::postProcess();
  }

}
