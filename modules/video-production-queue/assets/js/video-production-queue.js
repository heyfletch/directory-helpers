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
                    
                    // Start polling for status updates
                    startStatusPolling();
                    
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
     * Status polling
     */
    let statusPollInterval = null;
    
    function startStatusPolling() {
        if (statusPollInterval) {
            return;
        }
        
        statusPollInterval = setInterval(function() {
            updateQueueStatus();
        }, 30000); // Poll every 30 seconds (videos take ~10 minutes)
    }
    
    function stopStatusPolling() {
        if (statusPollInterval) {
            clearInterval(statusPollInterval);
            statusPollInterval = null;
        }
    }
    
    function updateQueueStatus() {
        $.ajax({
            url: dhVideoQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_get_video_queue_status',
                nonce: dhVideoQueue.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update status
                    if (data.is_active) {
                        $('#dh-queue-status').html('<span style="color: #46b450;">● Active</span>');
                    } else {
                        $('#dh-queue-status').html('<span style="color: #999;">● Stopped</span>');
                    }
                    
                    // Update currently processing
                    if (data.current_post_title) {
                        $('#dh-current-post-title').html('<a href="' + data.current_post_link + '" target="_blank">' + data.current_post_title + '</a>');
                        $('#dh-current-processing').show();
                    } else {
                        $('#dh-current-processing').hide();
                    }
                    
                    // Update next in queue
                    if (data.next_post_title) {
                        $('#dh-next-post-title').html('<a href="' + data.next_post_link + '" target="_blank">' + data.next_post_title + '</a> <span style="color: #666;">(' + data.next_post_type + ')</span>');
                        $('#dh-next-in-queue').show();
                        $('#dh-no-posts-msg').hide();
                    } else {
                        $('#dh-next-in-queue').hide();
                        $('#dh-no-posts-msg').show();
                    }
                    
                    // If queue is no longer active, stop polling and reload
                    if (!data.is_active) {
                        stopStatusPolling();
                        showNotice('Queue completed!', 'success');
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    }
                }
            }
        });
    }
    
    // Start polling if queue is active on page load
    if (dhVideoQueue.isActive) {
        startStatusPolling();
    }
    
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
