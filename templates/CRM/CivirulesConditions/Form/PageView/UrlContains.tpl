<h3>{$ruleConditionHeader}</h3>
<div class="crm-block crm-form-block crm-civirule-rule_condition-block-pageview_urlcontains">
  <div class="crm-section">
    <div class="label">{$form.url_pattern.label}</div>
    <div class="content">
      {$form.url_pattern.html}
      <div class="description">{ts}Use * as a wildcard. Example: civicrm/event/* matches all event pages.{/ts}</div>
    </div>
    <div class="clear"></div>
  </div>
</div>
<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
