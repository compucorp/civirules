{* block for rule data *}
{crmScope extensionKey='org.civicoop.civirules'}
<h3>{ts}Rule Details{/ts}</h3>
<div class="crm-block crm-form-block crm-civirule-rule_label-block">
  <div class="crm-section">
    <div class="label">{$form.rule_label.label}</div>
    <div class="content">{$form.rule_label.html}</div>
    <div class="clear"></div>
  </div>
  <div class="crm-section">
    <div class="label">{$form.rule_description.label}</div>
    <div class="content">{$form.rule_description.html}</div>
    <div class="clear"></div>
  </div>
  <div class="crm-section">
    <div class="label">{$form.rule_tag_id.label}</div>
    <div class="content select-container">{$form.rule_tag_id.html}</div>
    <div class="clear"></div>
  </div>
  <div class="crm-section">
    <div class="label">{$form.rule_help_text.label}</div>
    <div class="content">{$form.rule_help_text.html}</div>
    <div class="clear"></div>
  </div>
  <div class="crm-section">
    <div class="label">{$form.rule_is_active.label}</div>
    <div class="content">{$form.rule_is_active.html}
    {if !empty($clones)}
        <br><span class="description font-red">{ts 1=$clones}Duplicate rules detected: %1{/ts}</span>
        <br><span class="description font-red">{ts}Enabling can result in unintended duplicate actions{/ts}</span>
    {/if}
    </div>
    <div class="clear"></div>
  </div>
  {if !empty($form.rule_is_debug)}
  <div class="crm-section">
    <div class="label">{$form.rule_is_debug.label}</div>
    <div class="content">{$form.rule_is_debug.html}
    <br><span class="description">{ts}When enabled, detailed condition evaluation and the full trigger data for each firing are written to the CiviRules debug log.{/ts}</span>
    </div>
    <div class="clear"></div>
  </div>
  {/if}
  <div class="crm-section">
    <div class="label">{$form.rule_created_date.label}</div>
    <div class="content">{$form.rule_created_date.value}</div>
    <div class="clear"></div>
  </div>
  <div class="crm-section">
    <div class="label">{$form.rule_created_contact.label}</div>
    <div class="content">{$form.rule_created_contact.value}</div>
    <div class="clear"></div>
  </div>
  {$postRuleBlock}
</div>
{/crmScope}
{if !empty($clones)}
{literal}
<script>
  CRM.$('#rule_is_active').on('change',function(){
    if(this.checked){
        CRM.alert(CRM.ts('Enabling can result in unintended double actions'))
      }}
  );
</script>
{/literal}
{/if}
