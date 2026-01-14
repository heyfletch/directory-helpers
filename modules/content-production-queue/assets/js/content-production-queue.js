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
                    
                    // Update status to Running
                    $('#dh-cpq-status-text').html('<span style="color: #46b450;">▶️ Running</span>');
                    
                    // Hide start buttons
                    $('#dh-start-cpq-healthy-btn, #dh-start-cpq-all-btn').hide();
                    
                    // Hide only eligible rows being published
                    hideEligibleRows(mode);
                    
                    // Show processing message if provided
                    if (response.data.total) {
                        $('<div class="dh-cpq-processing-msg" style="background: #fff; padding: 20px; margin: 10px 0; border-left: 4px solid #46b450; box-shadow: 0 1px 1px rgba(0,0,0,.04);"><p style="margin: 0; color: #46b450;"><span class="dashicons dashicons-update-alt" style="animation: rotation 2s infinite linear; display: inline-block;"></span> <strong>Publishing ' + response.data.total + ' eligible cities...</strong></p><style>@keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(359deg); }}</style></div>').insertAfter('.dh-cpq-status-box');
                    }
                    
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
     * Hide eligible rows based on mode
     */
    function hideEligibleRows(mode) {
        $('.dh-cpq-draft-posts table tbody tr').each(function() {
            const $row = $(this);
            const health = $row.data('health');
            const hasImages = $row.data('has-images') == 1;
            
            // Check if this row is eligible based on mode and requirements
            let isEligible = false;
            
            if (hasImages) {
                if (mode === 'healthy') {
                    // Only all_ok or not_exists (no value means not checked yet)
                    isEligible = (health === 'all_ok' || health === '' || !health);
                } else {
                    // Include warnings too
                    isEligible = (health === 'all_ok' || health === 'warning' || health === '' || !health);
                }
            }
            
            if (isEligible) {
                $row.fadeOut(400);
            }
        });
    }
    
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
    
    /**
     * Handle featured image click to trigger webhook
     */
    $(document).on('click', '.dh-trigger-thumb-link', function(e) {
        e.preventDefault();
        
        const link = $(this);
        const postId = link.data('post-id');
        const nonce = link.data('nonce');
        
        // Add loading state
        link.css('opacity', '0.5').css('pointer-events', 'none');
        
        $.ajax({
            url: dhContentQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_trigger_featured_image_webhook',
                post_id: postId,
                nonce: nonce
            },
            success: function(response) {
                link.css('opacity', '1').css('pointer-events', 'auto');
                
                if (response.success) {
                    showNotice(dhContentQueue.successMessageThumb, 'success');
                } else {
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : dhContentQueue.errorMessage;
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                link.css('opacity', '1').css('pointer-events', 'auto');
                showNotice(dhContentQueue.errorMessage + ' (' + error + ')', 'error');
            }
        });
    });
    
    /**
     * Handle Refresh Page button
     */
    $(document).on('click', '#dh-refresh-page-btn', function(e) {
        e.preventDefault();
        window.location.reload();
    });
    
    /**
     * Handle Open Non-Healthy Cities button
     */
    $(document).on('click', '#dh-open-non-healthy-btn', function(e) {
        e.preventDefault();
        
        // Find all rows with warning, red_alert, or unchecked health status
        const rows = $('.dh-cpq-draft-posts table tbody tr');
        const nonHealthyRows = [];
        
        rows.each(function() {
            const $row = $(this);
            const health = $row.attr('data-health'); // Use attr() instead of data() to get raw string value
            
            // Include warning, red_alert, and unchecked (empty or no health status)
            if (health === 'warning' || health === 'red_alert' || !health || health === '') {
                const postId = $row.attr('data-post-id');
                if (postId) {
                    nonHealthyRows.push(postId);
                }
            }
        });
        
        if (nonHealthyRows.length === 0) {
            alert('No non-healthy cities found.');
            return;
        }
        
        // Open each post's edit link in a new tab
        nonHealthyRows.forEach(function(postId) {
            const editUrl = 'post.php?post=' + postId + '&action=edit';
            window.open(editUrl, '_blank');
        });
    });
});
