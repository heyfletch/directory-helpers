jQuery(document).ready(function($) {
    
    /**
     * Handle Start Queue buttons (Healthy and All)
     */
    $(document).on('click', '#dh-start-cpq-healthy-btn, #dh-start-cpq-all-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const mode = button.data('mode'); // 'healthy' or 'all'
        const confirmMsg = mode === 'healthy' 
            ? 'Start publishing healthy cities only? This will publish posts with "All Ok" link health.'
            : 'Start publishing all cities? This will include posts with link health warnings.';
        
        if (!confirm(confirmMsg)) {
            return;
        }
        
        button.prop('disabled', true);
        button.text('Starting...');
        
        $.ajax({
            url: dhContentQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_start_content_queue',
                nonce: dhContentQueue.nonce,
                mode: mode
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
                    button.text('Start Publishing Queue');
                    
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : 'Failed to start queue';
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false);
                button.text('Start Publishing Queue');
                showNotice('Error: ' + error, 'error');
            }
        });
    });
    
    /**
     * Handle Stop Queue button
     */
    $(document).on('click', '#dh-stop-cpq-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        
        if (!confirm('Stop content publishing queue?')) {
            return;
        }
        
        button.prop('disabled', true);
        button.text('Stopping...');
        
        $.ajax({
            url: dhContentQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_stop_content_queue',
                nonce: dhContentQueue.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotice(response.data.message, 'success');
                    
                    // Stop polling
                    stopStatusPolling();
                    
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
    $(document).on('click', '#dh-reset-cpq-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        
        if (!confirm('Reset queue counters? This will reset the published count.')) {
            return;
        }
        
        button.prop('disabled', true);
        button.text('Resetting...');
        
        $.ajax({
            url: dhContentQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_reset_content_queue',
                nonce: dhContentQueue.nonce
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
                    button.text('Reset Counters');
                    
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : 'Failed to reset queue';
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false);
                button.text('Reset Counters');
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
        }, 3000); // Poll every 3 seconds
    }
    
    function stopStatusPolling() {
        if (statusPollInterval) {
            clearInterval(statusPollInterval);
            statusPollInterval = null;
        }
    }
    
    function updateQueueStatus() {
        $.ajax({
            url: dhContentQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_get_content_queue_status',
                nonce: dhContentQueue.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update UI
                    $('#dh-cpq-eligible-count').text(data.total_eligible);
                    $('#dh-cpq-published-count').text(data.published_count);
                    $('#dh-cpq-queue-count').text(data.total_eligible);
                    
                    if (data.current_post_title) {
                        $('#dh-cpq-current-post').text(data.current_post_title);
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
    if (dhContentQueue.isActive) {
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
