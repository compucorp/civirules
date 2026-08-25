// Swap the dialog to the configuration form of whichever action/condition type is
// picked, so adding one is a single step.
(function($) {
  'use strict';

  $(document).on('change', '.crm-civirules-type-select', function() {
    var $select = $(this),
      $container = $select.closest('.crm-ajax-container, .ui-dialog-content'),
      urls,
      url;

    if (!$container.length || !$container.data('civiCrmSnippet')) {
      return;
    }
    try {
      urls = JSON.parse($select.attr('data-civirules-type-urls') || '{}');
    }
    catch (e) {
      return;
    }
    url = urls[$select.val()];
    if (!url) {
      return;
    }

    // crm.ajax.js decides whether a successful save may close the dialog by comparing
    // against _originalUrl, so the new url has to become the original one - otherwise
    // saving leaves the dialog open.
    $container.data('civiCrmSnippet')._originalUrl = url;
    $container.crmSnippet('option', 'url', url).crmSnippet('refresh');
  });

})(CRM.$);
