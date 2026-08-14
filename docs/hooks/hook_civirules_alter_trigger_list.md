# hook_civirules_alter_trigger_list

## Description

This hook is called just before the list of triggers offered on the "Select
Trigger" dropdown (when adding a new rule) is built. It lets an extension
remove or alter entries - e.g. to collapse several `civirule_trigger` rows
that share one trigger class down to a single visible entry, when those rows
only exist so a rule can be reassigned between them after creation rather
than representing genuinely distinct triggering events.

## Definition

```php
hook_civirules_alter_trigger_list(array &$triggerList)
```

`$triggerList` is indexed by `civirule_trigger.id`; each entry carries the
full row (`id`, `label`, `name`, `class_name`, `object_name`, `op`).

## Returns

-   `NULL`

## Example

The example below hides every "Manual trigger" row except the first one, so
picking a different entity later (via the trigger's own entity-picker) does
not add extra entries to this list.

```php
function civirulesextras_civirules_alter_trigger_list(array &$triggerList) {
  $keptManualId = NULL;
  foreach ($triggerList as $id => $detail) {
    if (($detail['class_name'] ?? NULL) === 'CRM_Civirulesextras_CivirulesTrigger_Manual') {
      if ($keptManualId === NULL) {
        $keptManualId = $id;
      }
      else {
        unset($triggerList[$id]);
      }
    }
  }
}
```
