<h3>{$ruleTriggerHeader}</h3>
<div class="crm-block crm-form-block crm-civirule-cron_trigger-block-activity_date">
  <div class="help">{$ruleTriggerHelp}</div>
  <div class="crm-section">
    <div class="label">{$form.activity_type_id.label}</div>
    <div class="content">{$form.activity_type_id.html}</div>
    <div class="clear"></div>
  </div>
  <div class="crm-section">
    <div class="label">{$form.activity_status_id.label}</div>
    <div class="content">{$form.activity_status_id.html}</div>
    <div class="clear"></div>
  </div>
  <div class="crm-section">
    <div class="label">{$form.record_type.label}</div>
    <div class="content">{$form.record_type.html}</div>
    <div class="clear"></div>
  </div>
  <div class="crm-section">
    <div class="label">{$form.case_activity.label}</div>
    <div class="content">{$form.case_activity.html}</div>
    <div class="clear"></div>
  </div>
  {if isset($form.trigger_from_date)}
    <div class="crm-section">
      <div class="label">{$form.trigger_from_date.label}</div>
      <div class="content">
        {$form.trigger_from_date.html}
        <div class="description">{ts}Leave empty to use the date this rule was created. Set this when enabling a rule that was created some time ago, to stop it acting on entities that predate it.{/ts}</div>
      </div>
      <div class="clear"></div>
    </div>
  {/if}
  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>

