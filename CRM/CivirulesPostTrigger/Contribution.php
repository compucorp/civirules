<?php
/**
 * @author Jaap Jansma <jaap.jansma@civicoop.org>
 * @license http://www.gnu.org/licenses/agpl-3.0.html
 */
/**
 * Trigger when a Contribution is changed or added.
 */
 class CRM_CivirulesPostTrigger_Contribution extends CRM_Civirules_Trigger_Post {

   protected function getAdditionalEntities() {
     $entities = parent::getAdditionalEntities();
     $entities[] = new CRM_Civirules_TriggerData_EntityDefinition('Participant', 'Participant', 'CRM_Event_DAO_Participant', 'Participant');
     return $entities;
   }

  /**
   * Override alter trigger data.
   *
   * When a contribution is added/updated after an online payment is made
   * contact_id and financial_type_id are not present in the data in the post hook.
   * So we should retrieve this data from the database if it's not present.
   */
  public function alterTriggerData(CRM_Civirules_TriggerData_TriggerData &$triggerData) {
    try {
      $dataFromPostHook = $triggerData->getEntityData('Contribution');
      if (!isset($dataFromPostHook['contact_id']) || !isset($dataFromPostHook['financial_type_id'])) {
        $dataInDatabase = civicrm_api3('Contribution', 'getsingle', array('id' => $dataFromPostHook['id']));
        // Merge both arrays preserving the data in the posthook.
        $newData = array_merge($dataInDatabase, $dataFromPostHook);
        $triggerData->setEntityData('Contribution', $newData);
      }
      if (CRM_Civirules_Utils_ContributionTrigger::getParticipantId()) {
        try {
          $participant = civicrm_api3('Participant', 'getsingle', ['id' => CRM_Civirules_Utils_ContributionTrigger::getParticipantId()]);
          $triggerData->setEntityData('Participant', $participant);
        }
        catch (Exception $e) {
          // Do nothing
        }
      }
      else {
        $participantId = CRM_Core_DAO::singleValueQuery('SELECT participant_id from civicrm_participant_payment WHERE contribution_id = %1', [
          1 => [$dataFromPostHook['id'], 'Integer'],
        ]);
        try {
          $participant = civicrm_api3('Participant', 'getsingle', ['id' => $participantId]);
          $triggerData->setEntityData('Participant', $participant);
        }
        catch (Exception $e) {
          // Do nothing
        }
      }
    } catch (Exception $e) {
      // Do nothing. There could be an exception when the contribution does not exists in the database anymore.
    }

    parent::alterTriggerData($triggerData);
  }

}
