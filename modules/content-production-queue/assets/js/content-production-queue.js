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
                    
                    // Update button states
                    $('#dh-start-cpq-healthy-btn, #dh-start-cpq-all-btn').hide();
                    
                    // Start batch processing
                    startBatchProcessing();
                } else {
                    button.prop('disabled', false);
                    const originalText = mode === 'healthy' ? 'Publish Healthy Cities' : 'Publish All Cities';
                    button.text(originalText);
                    
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
                    
                    // Stop batch processing
                    stopBatchProcessing();
                    
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
     * Batch processing and status polling
     */
    let processingInterval = null;
    
    function startBatchProcessing() {
        if (processingInterval) {
            return;
        }
        
        // Process immediately, then every 3 seconds
        processBatch();
        
        processingInterval = setInterval(function() {
            processBatch();
        }, 5000); // Process batch every 5 seconds
    }
    
    function stopBatchProcessing() {
        if (processingInterval) {
            clearInterval(processingInterval);
            processingInterval = null;
        }
    }
    
    function processBatch() {
        $.ajax({
            url: dhContentQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_process_content_batch',
                nonce: dhContentQueue.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update current post
                    if (data.current_post_title) {
                        $('#dh-cpq-current-post').text(data.current_post_title);
                    }
                    
                    // Update published count if element exists
                    if (data.published_count !== undefined && $('#dh-cpq-published-count').length) {
                        $('#dh-cpq-published-count').text(data.published_count);
                    }
                    
                    // If queue is no longer active, stop processing and reload
                    if (!data.is_active) {
                        stopBatchProcessing();
                        showNotice('Queue completed! Published ' + data.published_count + ' posts.', 'success');
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    }
                }
            },
            error: function() {
                // On error, just continue - cron will pick it up
            }
        });
    }
    
    // Start batch processing if queue is active on page load
    if (dhContentQueue.isActive) {
        startBatchProcessing();
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
    
    /**
     * Handle Recheck All Link Health button
     */
    $(document).on('click', '#dh-recheck-all-health-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const status = $('#dh-recheck-status');
        
        if (!confirm('Recalculate link health for all draft posts? This will update health status based on existing link checks (no new HTTP requests).')) {
            return;
        }
        
        button.prop('disabled', true);
        button.text('Rechecking...');
        status.html('<span style="color: #999;">Processing...</span>');
        
        $.ajax({
            url: dhContentQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_recheck_all_link_health',
                nonce: dhContentQueue.nonce
            },
            success: function(response) {
                button.prop('disabled', false);
                button.text('Recheck All Link Health');
                
                if (response.success) {
                    status.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
                    showNotice(response.data.message + ' - Refreshing page...', 'success');
                    
                    // Reload page after 2 seconds to show updated health status
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : 'Failed to recheck link health';
                    status.html('<span style="color: #dc3232;">✗ ' + errorMsg + '</span>');
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false);
                button.text('Recheck All Link Health');
                status.html('<span style="color: #dc3232;">✗ Error</span>');
                showNotice('AJAX error: ' + error, 'error');
            }
        });
    });
});
