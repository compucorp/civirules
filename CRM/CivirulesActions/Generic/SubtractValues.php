<?php
/**
 * Class for CiviRules Advanced Update Date Action Form
 *
 * @author David Hayes (Black Brick Software) <david@blackbrick.software>
 * @license AGPL-3.0
 */

use CRM_Civirules_ExtensionUtil as E;

class CRM_CivirulesActions_Generic_SubtractValues extends CRM_Civirules_Action {

  /**
   * Method processAction to execute the action
   *
   * @param CRM_Civirules_TriggerData_TriggerData $triggerData
   * @access public
   */
  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    static $alreadyDone = [];
    if (isset($alreadyDone[$this->ruleAction['id']][$triggerData->getEntityId()])) {
      return;
    }
    $alreadyDone[$this->ruleAction['id']][$triggerData->getEntityId()] = TRUE;
    $action_params = $this->getActionParameters();
    if (empty($action_params)) {
      return;
    }
    $newValue = 0;
    foreach(explode(",",$action_params['source_fields']) as $source_field) {
      try {
        list($source_entity, $source_field_id) = $this->parseRawFieldId($source_field);
        $entity = $triggerData->getEntityData($source_entity);
        $newValue -= $this->getValue($source_entity, $source_field_id, $entity['id'], $triggerData);
      }
      catch (Exception $e) {
        Civi::log()->debug('Error parsing source field name (' . $e->getMessage() . ')');
        return;
      }
    }

    try {
      list($target_entity, $target_field_id) = $this->parseRawFieldId($action_params['target_field']);
      $entity = $triggerData->getEntityData($target_entity);
      $this->setValue($target_entity, $target_field_id, $entity['id'], $newValue);
    }
    catch (Exception $e) {
      Civi::log()->debug('Error parsing target field name (' . $e->getMessage() . ')');
      return;
    }
  }

  /**
   * Parse saved field id into entity type and field id (eg Contact:10 to Contact and 10)
   *
   * @param string $raw_field_id
   * @return array [entity_type, field_id]
   * @throws Exception when field id is invalid
   */
  protected function parseRawFieldId($raw_field_id) {

    $field_parts = explode('::', $raw_field_id);

    if (count($field_parts) !== 2) {
      throw new Exception("Invalid field format '{$raw_field_id}'.");
    }

    list($entity_type, $field_id) = $field_parts;

    $entity_search = civicrm_api3('Entity', 'get', [
      'sequential' => 1,
    ]);

    $entities = array_map('strtolower', $entity_search['values']);
    if (!in_array(strtolower($entity_type), $entities)) {
      throw new Exception("Invalid entity for field '{$raw_field_id}'.");
    }

    return $field_parts;
  }

  /**
   * Set the value to the given field
   *
   * @param string $entity_type entity type of primary object trigger; Only Contact is supported
   * @param int $entity_id      entity ID of primary object trigger
   * @param string $field_id    field ID or special fields like 'contact_id'
   * @param string $new_value   new value to set
   * @return void
   * @throws Exception when unable to set value
   */
  protected function setValue($entity_type, $field_id, $entity_id, $new_value) {
    civicrm_api3($entity_type, 'create', [
      'id' => $entity_id,
      $field_id => $new_value,
    ]);
  }

  /**
   * Get the value of the given field for the given contact
   *
   * @param string $entity_type entity type of primary object trigger
   * @param int $entity_id      entity ID of primary object trigger
   * @param string $field_id    field ID or special fields like 'contact_id'
   * @return mixed              current value
   * @throws Exception when unable to retrieve value
   */
  protected function getValue($entity_type, $field_id, $entity_id, CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $entityData = $triggerData->getEntityData($entity_type);
    if (isset($entityData[$field_id])) {
      return $entityData[$field_id];
    }        
    return civicrm_api3($entity_type, 'getvalue', [
      'id' => $entity_id,
      'return' => $field_id,
    ]);
  }

  /**
   * Method to return the url for additional form processing for action
   * and return false if none is needed
   *
   * @param int $ruleActionId
   * @return bool
   * @access public
   */
  public function getExtraDataInputUrl($ruleActionId) {
    return $this->getFormattedExtraDataInputUrl('civicrm/civirule/form/action/generic/sumvalues', $ruleActionId);
  }

  /**
   * Returns a user friendly text explaining the condition params
   * e.g. 'Older than 65'
   *
   * @return string
   * @access public
   * @throws \CRM_Core_Exception
   */
  public function userFriendlyConditionParams() {

    $action_params = $this->getActionParameters();

    try {
      list($target_entity, $target_field_id) = $this->parseRawFieldId($action_params['target_field']);
      $target_field = '"' . $this->getHumanReadableFieldLabel($target_field_id, $target_entity, 'create') . '" (' . $target_entity . ')';
    }
    catch (Exception $e) {
      Civi::log()->debug('Action: Error parsing target field name (' . $e->getMessage() . ')');
      return;
    }

    $source_fields = [];
    foreach(explode(",",$action_params['source_fields']) as $source_field) {
      try {
        list($source_entity, $source_field_id) = $this->parseRawFieldId($source_field);
        $source_fields[] = '"' . $this->getHumanReadableFieldLabel($source_field_id, $source_entity, 'get') . '" (' . $source_entity . ')';
      }
      catch (Exception $e) {
        Civi::log()->debug('Action: Error parsing source field name (' . $e->getMessage() . ')');
        return;
      }
    }
    return E::ts('Subtract fields: %1 and store it into %2', [1=>implode(', ', $source_fields), 2=>$target_field]);
  }

  /**
   * Find the human readable label for a field
   *
   * @param string $field_identifier
   * @param string $entity
   * @access protected
   * @return string
   * @throws \CRM_Core_Exception
   */
  protected function getHumanReadableFieldLabel($field_identifier, $entity, $action = 'get') {
    if (str_starts_with($field_identifier, 'custom_')) {
      // Custom fields
      if (is_numeric(substr($field_identifier,7))) {
        $custom_field = civicrm_api3('CustomField', 'getsingle', [
          'id' => substr($field_identifier, 7),
        ]);
        return $custom_field['label'];
      }
    } else {
      $fields = civicrm_api3($entity, 'getfields', ['action' => $action, 'name' => $field_identifier]);
      $field = reset($fields['values']);
      if (isset($field['title'])) {
        return $field['title'];
      }
    }

    // Built in Fields
    return ucwords(str_replace('_', ' ', $field_identifier));
  }

}
