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

        // AI Prompts: Add new row
        $(document).on('click', '#dh-add-prompt', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var nextIndex = parseInt($btn.attr('data-next-index'), 10) || 0;
            var rowHtml = '' +
                '<div class="dh-prompt-row" style="margin-bottom:12px; border:1px solid #ccd0d4; padding:12px; background:#fff;">' +
                    '<p>' +
                        '<label>' +
                            '<strong>Key</strong><br>' +
                            '<input type="text" name="directory_helpers_prompts[' + nextIndex + '][key]" value="" class="regular-text" placeholder="e.g. city_page_intro">' +
                        '</label>' +
                    '</p>' +
                    '<p>' +
                        '<label>' +
                            '<strong>Prompt</strong><br>' +
                            '<textarea name="directory_helpers_prompts[' + nextIndex + '][value]" rows="6" class="large-text code" placeholder="Paste your prompt here..."></textarea>' +
                        '</label>' +
                    '</p>' +
                    '<p>' +
                        '<button type="button" class="button-link-delete dh-remove-prompt">Remove</button>' +
                    '</p>' +
                '</div>';
            $('#dh-prompts-rows').append(rowHtml);

            // If post-type template exists, inject it before the Remove row
            var tpl = $('#dh-prompt-pt-template').html();
            if (tpl) {
                var $row = $('#dh-prompts-rows .dh-prompt-row').last();
                var ptHtml = tpl.replace(/__INDEX__/g, nextIndex);
                var $lastP = $row.find('p').last(); // the Remove button row
                $lastP.before(ptHtml);
            }
            $btn.attr('data-next-index', nextIndex + 1);
        });

        // AI Prompts: Remove row
        $(document).on('click', '.dh-remove-prompt', function(e) {
            e.preventDefault();
            $(this).closest('.dh-prompt-row').remove();
        });
    });
})(jQuery);
