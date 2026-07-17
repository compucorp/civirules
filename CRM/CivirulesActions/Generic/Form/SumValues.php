<?php
/**
 * Class for CiviRules Advanced Update Date Action Form
 *
 * @author David Hayes (Black Brick Software) <david@blackbrick.software>
 * @license AGPL-3.0
 */

use CRM_Civirules_ExtensionUtil as E;

class CRM_CivirulesActions_Generic_Form_SumValues extends CRM_CivirulesActions_Form_Form {

  /**
   * Overridden parent method to build the form
   *
   * @access public
   */
  public function buildQuickForm() {
    $this->add('hidden', 'rule_action_id');

    $this->add('select2',
      'source_fields',
      E::ts('Source Fields'),
      $this->getEligibleCustomFields('get'),
      TRUE,
      ['multiple' => TRUE]);

    $this->add('select2',
      'target_field',
      E::ts('Target Field'),
      $this->getEligibleCustomFields('create'),
      TRUE);

    // set defaults
    $this->setDefaults($this->ruleAction->unserializeParams());

    // set defaults
    $this->setDefaults($this->ruleAction->unserializeParams());

    $this->addButtons([
      ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  

  /**
   * Overridden parent method to process form data after submitting
   *
   * @access public
   */
  public function postProcess() {
    $values = $this->exportValues();
    $configuration = [
      'source_fields'  => $values['source_fields'] ?? NULL,
      'target_field'  => $values['target_field'] ?? NULL,
    ];

    $this->ruleAction->action_params = serialize($configuration);
    $this->ruleAction->save();
    parent::postProcess();
  }

  /**
   * Get a list of all numeric contact custom fields
   *
   * @return array list of field IDs
   */
  protected function getEligibleCustomFields($action = 'get') {
    static $field_list = [];
    $skipFields = [
      'id',
    ];
    if (!array_key_exists($action, $field_list)) {
      foreach ($this->triggerClass->getProvidedEntities() as $entityDef) {
        $entity = $entityDef->entity;
        if ($entity == 'Contact') {
          $entity = ['Contact', 'Individual', 'Organization', 'Household'];
        }
        $fields = civicrm_api3($entityDef->entity, 'getfields', ['action' => $action]);
        foreach($fields['values'] as $field) {
          if (str_starts_with($field['name'], 'custom_')) {
            continue;
          }
          if ($action != 'get' && in_array($field['name'], $skipFields)) {
            continue;
          }
          $field_list[$action][$entityDef->entity . '::' . $field['name']] = [
            'id' => $entityDef->entity . '::' . $field['name'],
            'label' => $entityDef->entity . '.' . $field['title'],
          ];
        }


        $eligible_group_ids = [];
        $group_query = civicrm_api3('CustomGroup', 'get', [
          'extends' => ['IN' => (array) $entity],
          'is_active' => 1,
          'option.limit' => 0,
          'return' => 'id,title,extends',
        ]);
        foreach ($group_query['values'] as $group) {
          $eligible_group_ids[$group['id']] = $group['title'];
        }

        // find eligible fields
        if (!$eligible_group_ids) {
          continue;
        }
        $field_query = civicrm_api3('CustomField', 'get', [
          'custom_group_id' => ['IN' => array_keys($eligible_group_ids)],
          'is_active' => 1,
          'option.limit' => 0,
          'return' => 'id,label,custom_group_id',
        ]);
        foreach ($field_query['values'] as $field) {
          $field_list[$action][$entityDef->entity . '::custom_' . $field['id']] = [
            'id' => $entityDef->entity . '::custom_' . $field['id'],
            'label' => $entityDef->entity . '.' . $eligible_group_ids[$field['custom_group_id']] . '.' . $field['label'],
          ];
        }
      }
    }
    return $field_list[$action];
  }

}
