(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.dcloudImportAdmin = {
    attach: function (context, settings) {
      // Add JSON validation and formatting features
      $('#edit-json-input', context).once('dcloud-import').each(function() {
        var $textarea = $(this);
        
        // Add a format button
        var $formatButton = $('<button type="button" class="button">Format JSON</button>');
        $formatButton.insertAfter($textarea);
        
        $formatButton.on('click', function(e) {
          e.preventDefault();
          try {
            var jsonText = $textarea.val();
            var jsonObj = JSON.parse(jsonText);
            var formattedJson = JSON.stringify(jsonObj, null, 2);
            $textarea.val(formattedJson);
          } catch (error) {
            alert('Invalid JSON: ' + error.message);
          }
        });
        
        // Add syntax highlighting class for better readability
        $textarea.addClass('json-input');
      });
      
      // Auto-expand example textarea
      $('.dcloud-import-example textarea', context).each(function() {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
      });
    }
  };

})(jQuery, Drupal);