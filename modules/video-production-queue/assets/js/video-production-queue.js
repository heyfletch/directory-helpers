jQuery(document).ready(function($) {
    
    /**
     * Handle Start Queue button
     */
    $(document).on('click', '#dh-start-queue-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        
        if (!confirm('Start video production queue? This will begin processing posts automatically.')) {
            return;
        }
        
        button.prop('disabled', true);
        button.text('Starting...');
        
        $.ajax({
            url: dhVideoQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_start_video_queue',
                nonce: dhVideoQueue.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    
                    // Reload page after 2 seconds
                    setTimeout(function() {
                        window.location.reload();
                    }, 2000);
                } else {
                    button.prop('disabled', false);
                    button.text('Start Video Production Queue');
                    
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : 'Failed to start queue';
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false);
                button.text('Start Video Production Queue');
                showNotice('Error: ' + error, 'error');
            }
        });
    });
    
    /**
     * Handle Stop Queue button
     */
    $(document).on('click', '#dh-stop-queue-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        
        if (!confirm('Stop video production queue?')) {
            return;
        }
        
        button.prop('disabled', true);
        button.text('Stopping...');
        
        $.ajax({
            url: dhVideoQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_stop_video_queue',
                nonce: dhVideoQueue.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    
                    // Reload page after 1 second
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    button.prop('disabled', false);
                    button.text('Stop Queue');
                    
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : 'Failed to stop queue';
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false);
                button.text('Stop Queue');
                showNotice('Error: ' + error, 'error');
            }
        });
    });
    
    /**
     * Handle Reset Queue button
     */
    $(document).on('click', '#dh-reset-queue-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        
        if (!confirm('Reset all queue counters? This will clear attempt counts and allow all posts to be processed again.')) {
            return;
        }
        
        button.prop('disabled', true);
        button.text('Resetting...');
        
        $.ajax({
            url: dhVideoQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_reset_video_queue',
                nonce: dhVideoQueue.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    
                    // Reload page after 1 second
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    button.prop('disabled', false);
                    button.text('Reset Queue Counters');
                    
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : 'Failed to reset queue';
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false);
                button.text('Reset Queue Counters');
                showNotice('Error: ' + error, 'error');
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
        
        // Insert after h1
        $('.wrap > h1').first().after(notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            notice.fadeOut(function() {
                notice.remove();
            });
        }, 5000);
    }
});
