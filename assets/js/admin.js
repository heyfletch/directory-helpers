/**
 * Directory Helpers Admin JavaScript
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Toggle module settings visibility when checkbox is clicked
        $('.directory-helpers-modules input[type="checkbox"]').on('change', function() {
            var moduleId = $(this).val();
            var settingsDiv = $('#settings-' + moduleId);
            
            if ($(this).is(':checked')) {
                settingsDiv.addClass('active');
            } else {
                settingsDiv.removeClass('active');
            }
        });
        
        // Initialize settings visibility
        $('.directory-helpers-modules input[type="checkbox"]:checked').each(function() {
            var moduleId = $(this).val();
            $('#settings-' + moduleId).addClass('active');
        });
    });
})(jQuery);
