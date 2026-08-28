{*
  Type picker rendered at the top of a configuration form, so the type and its
  configuration are chosen in a single dialog. Selecting a different type swaps the
  dialog to that type's configuration form - see js/civirules-add.js.
*}
<div class="crm-section crm-civirules-type-picker">
  {if array_key_exists('rule_action_select', $form)}
    <div class="label">{$form.rule_action_select.label}</div>
    <div class="content">{$form.rule_action_select.html}</div>
  {elseif array_key_exists('rule_condition_select', $form)}
    <div class="label">{$form.rule_condition_select.label}</div>
    <div class="content">{$form.rule_condition_select.html}</div>
  {/if}
  <div class="clear"></div>
</div>
