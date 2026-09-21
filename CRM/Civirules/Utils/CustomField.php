<?php

/**
 * Util functions for custom fields.
 */
class CRM_Civirules_Utils_CustomField {

  /**
   * Returns whether a custom field is multi select field.
   * @param $customfield_id
   * @return bool
   * @throws CRM_Core_Exception
   */
  public static function isCustomFieldMultiselect($customfield_id): bool {
    return (bool) \Civi\Api4\CustomField::get(FALSE)
      ->addWhere('id', '=', $customfield_id)
      ->addWhere('serialize', '=', TRUE)
      ->execute()->count();
  }

}
