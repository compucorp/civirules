<?php


class CRM_CivirulesActions_Relationship_SwitchHeadOfHousehold extends CRM_Civirules_Action {

  /**
   * Process the action
   *
   * @param CRM_Civirules_TriggerData_TriggerData $triggerData
   */
  public function processAction(CRM_Civirules_TriggerData_TriggerData $triggerData) {
    $contactId = $triggerData->getContactId();
    $relationships = \Civi\Api4\Relationship::get(FALSE)
      ->addWhere('contact_id_a', '=', $contactId)
      ->addWhere('relationship_type_id:name', '=', 'Head of Household for')
      ->addWhere('is_active', '=', TRUE)
      ->execute();
    foreach ($relationships as $relationship) {
      try {
        $memberRelationship = \Civi\Api4\Relationship::get(FALSE)
          ->addWhere('contact_id_b', '=', $relationship['contact_id_b'])
          ->addWhere('relationship_type_id:name', '=', 'Household Member of')
          ->addWhere('is_active', '=', TRUE)
          ->addOrderBy('contact_id_a.birth_date', 'ASC')
          ->addOrderBy('id', 'ASC')
          ->execute()
          ->first();
        if ($memberRelationship) {
          \Civi\Api4\Relationship::update(FALSE)
            ->addValue('end_date', date('Y-m-d'))
            ->addValue('is_active', FALSE)
            ->addWhere('id', '=', $memberRelationship['id'])
            ->execute();
          \Civi\Api4\Relationship::create(FALSE)
            ->addValue('start_date', date('Y-m-d'))
            ->addValue('contact_id_a', $memberRelationship['contact_id_a'])
            ->addValue('contact_id_b', $memberRelationship['contact_id_b'])
            ->addValue('is_active', TRUE)
            ->addValue('relationship_type_id:name', 'Head of Household for')
            ->execute();

          \Civi\Api4\Relationship::update(FALSE)
            ->addValue('end_date', date('Y-m-d'))
            ->addValue('is_active', FALSE)
            ->addWhere('id', '=', $relationship['id'])
            ->execute();
          \Civi\Api4\Relationship::create(FALSE)
            ->addValue('start_date', date('Y-m-d'))
            ->addValue('contact_id_a', $relationship['contact_id_a'])
            ->addValue('contact_id_b', $relationship['contact_id_b'])
            ->addValue('is_active', TRUE)
            ->addValue('relationship_type_id:name', 'Household Member of')
            ->execute();
        }
      }
      catch (\Exception $e) {
        // Do nothing
      }
    }
  }

  public function getExtraDataInputUrl($ruleActionId) {
    return FALSE;
  }

}
