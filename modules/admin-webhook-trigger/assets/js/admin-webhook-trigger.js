jQuery(document).ready(function($) {
    
    /**
     * Handle click on AI content button
     */
    $(document).on('click', '.dh-trigger-ai-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const postId = button.data('post-id');
        const nonce = button.data('nonce');
        
        if (!confirm(dhWebhookTrigger.confirmMessageAI)) {
            return;
        }
        
        // Disable button and show loading state
        button.prop('disabled', true);
        const originalText = button.html();
        button.html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle; margin-top: 3px;"></span> Sending...');
        
        // Send AJAX request
        $.ajax({
            url: dhWebhookTrigger.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_trigger_ai_webhook',
                post_id: postId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    button.html('<span class="dashicons dashicons-yes" style="vertical-align: middle; margin-top: 3px; color: #46b450;"></span> Done');
                    
                    // Show success notice
                    showNotice(dhWebhookTrigger.successMessageAI, 'success');
                    
                    // Re-enable button after 3 seconds
                    setTimeout(function() {
                        button.prop('disabled', false);
                        button.html(originalText);
                    }, 3000);
                } else {
                    button.prop('disabled', false);
                    button.html(originalText);
                    
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : dhWebhookTrigger.errorMessage;
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false);
                button.html(originalText);
                showNotice(dhWebhookTrigger.errorMessage + ' (' + error + ')', 'error');
            }
        });
    });
    
    /**
     * Handle click on notebook button
     */
    $(document).on('click', '.dh-trigger-notebook-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const postId = button.data('post-id');
        const nonce = button.data('nonce');
        
        // Disable button and show loading state
        button.prop('disabled', true);
        const originalText = button.html();
        button.html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle; margin-top: 3px;"></span> Sending...');
        
        // Send AJAX request
        $.ajax({
            url: dhWebhookTrigger.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_trigger_notebook_webhook',
                post_id: postId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    button.html('<span class="dashicons dashicons-yes" style="vertical-align: middle; margin-top: 3px; color: #46b450;"></span> Done');
                    
                    // Show success notice
                    showNotice(dhWebhookTrigger.successMessageNotebook, 'success');
                    
                    // Re-enable button after 3 seconds
                    setTimeout(function() {
                        button.prop('disabled', false);
                        button.html(originalText);
                    }, 3000);
                } else {
                    button.prop('disabled', false);
                    button.html(originalText);
                    
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : dhWebhookTrigger.errorMessage;
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false);
                button.html(originalText);
                showNotice(dhWebhookTrigger.errorMessage + ' (' + error + ')', 'error');
            }
        });
    });
    
    /**
     * Handle click on row action link
     */
    $(document).on('click', '.dh-trigger-notebook-link', function(e) {
        e.preventDefault();
        
        const link = $(this);
        const postId = link.data('post-id');
        const nonce = link.data('nonce');
        
        if (!confirm(dhWebhookTrigger.confirmMessage)) {
            return;
        }
        
        // Show loading state
        const originalText = link.text();
        link.html('<span class="dashicons dashicons-update dashicons-spin" style="vertical-align: middle;"></span> Sending...');
        
        // Send AJAX request
        $.ajax({
            url: dhWebhookTrigger.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_trigger_notebook_webhook',
                post_id: postId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    link.html('<span class="dashicons dashicons-yes" style="color: #46b450; vertical-align: middle;"></span> Done');
                    
                    // Show success notice
                    showNotice(dhWebhookTrigger.successMessage, 'success');
                    
                    // Restore link after 3 seconds
                    setTimeout(function() {
                        link.html(originalText);
                    }, 3000);
                } else {
                    link.html(originalText);
                    
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : dhWebhookTrigger.errorMessage;
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                link.html(originalText);
                showNotice(dhWebhookTrigger.errorMessage + ' (' + error + ')', 'error');
            }
        });
    });
    
    /**
     * Show admin notice
     */
    function showNotice(message, type) {
        type = type || 'info';
        
        const noticeClass = 'notice notice-' + type + ' is-dismissible';
        const notice = $('<div class="' + noticeClass + '"><p>' + message + '</p></div>');
        
        // Insert after the first h1 or at the top of wpbody-content
        const target = $('.wrap > h1').first();
        if (target.length) {
            target.after(notice);
        } else {
            $('#wpbody-content').prepend(notice);
        }
        
        // Make dismissible
        notice.on('click', '.notice-dismiss', function() {
            notice.fadeOut(function() {
                notice.remove();
            });
        });
        
        // Auto-dismiss success notices after 5 seconds
        if (type === 'success') {
            setTimeout(function() {
                notice.fadeOut(function() {
                    notice.remove();
                });
            }, 5000);
        }
    }
});
