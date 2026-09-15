<?php
/**
 * Form for the "Relationship end date reached" cron trigger parameters:
 * only pick up relationships that ended within the last X days/weeks/months/years.
 *
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */

use CRM_Civirules_ExtensionUtil as E;

class CRM_CivirulesCronTrigger_Form_RelationshipEndDate extends CRM_CivirulesTrigger_Form_Form {

  /**
   * Overridden parent method to build form
   */
  public function buildQuickForm() {
    $this->add('hidden', 'rule_id');

    $this->add('text', 'interval', E::ts('Ended within the last'), ['size' => 4], TRUE);
    $this->addRule('interval', E::ts('Interval should be a whole number'), 'positiveInteger');
    $this->add('select', 'interval_unit', E::ts('Interval unit'), CRM_CivirulesCronTrigger_RelationshipEndDate::intervals(), TRUE);
    $this->addFormRule([__CLASS__, 'validateInterval']);

    $this->addButtons([
      ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  /**
   * Form rule: the interval must be greater than zero.
   *
   * @param array $values
   * @return array|bool
   */
  public static function validateInterval($values) {
    if (isset($values['interval']) && (int) $values['interval'] < 1) {
      return ['interval' => E::ts('Interval should be greater than 0')];
    }
    return TRUE;
  }

  /**
   * Overridden parent method to set default values
   *
   * @return array $defaultValues
   */
  public function setDefaultValues() {
    $defaultValues = parent::setDefaultValues();
    $data = unserialize($this->rule->trigger_params);
    if (!empty($data['interval'])) {
      $defaultValues['interval'] = $data['interval'];
    }
    if (!empty($data['interval_unit'])) {
      $defaultValues['interval_unit'] = $data['interval_unit'];
    }
    else {
      $defaultValues['interval_unit'] = 'days';
    }
    return $defaultValues;
  }

  /**
   * Overridden parent method to process form data after submission
   */
  public function postProcess() {
    $this->triggerParams['interval'] = $this->getSubmittedValue('interval');
    $this->triggerParams['interval_unit'] = $this->getSubmittedValue('interval_unit');
    parent::postProcess();
  }

}
