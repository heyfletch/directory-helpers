jQuery(document).ready(function($) {
    
    /**
     * Handle Stop Queue button
     */
    $(document).on('click', '#dh-stop-ppq-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        
        if (!confirm('Stop profile production queue?')) {
            return;
        }
        
        button.prop('disabled', true);
        button.text('Stopping...');
        
        $.ajax({
            url: dhProfileQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_stop_profile_queue',
                nonce: dhProfileQueue.nonce
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
    $(document).on('click', '#dh-reset-ppq-btn', function(e) {
        e.preventDefault();
        
        const button = $(this);
        
        if (!confirm('Reset queue? This will clear all queue data.')) {
            return;
        }
        
        button.prop('disabled', true);
        button.text('Resetting...');
        
        $.ajax({
            url: dhProfileQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_reset_profile_queue',
                nonce: dhProfileQueue.nonce
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
                    button.text('Reset Queue');
                    
                    const errorMsg = response.data && response.data.message 
                        ? response.data.message 
                        : 'Failed to reset queue';
                    showNotice(errorMsg, 'error');
                }
            },
            error: function(xhr, status, error) {
                button.prop('disabled', false);
                button.text('Reset Queue');
                showNotice('Error: ' + error, 'error');
            }
        });
    });
    
    /**
     * Handle Clear Error button
     */
    $(document).on('click', '#dh-clear-ppq-error', function(e) {
        e.preventDefault();
        $(this).closest('.notice').fadeOut();
    });
    
    /**
     * Batch processing and status polling
     */
    let processingInterval = null;
    
    function startBatchProcessing() {
        if (processingInterval) {
            return;
        }
        
        // Process immediately, then every 5 seconds
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
            url: dhProfileQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_process_profile_batch',
                nonce: dhProfileQueue.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update counters
                    if (data.processed_count !== undefined) {
                        $('#dh-ppq-processed').text(data.processed_count);
                    }
                    if (data.remaining !== undefined) {
                        $('#dh-ppq-remaining').text(data.remaining);
                    }
                    if (data.total !== undefined) {
                        $('#dh-ppq-total').text(data.total);
                    }
                    
                    // If queue is no longer active, stop processing and reload
                    if (!data.is_active) {
                        stopBatchProcessing();
                        if (data.last_error) {
                            showNotice('Queue stopped: ' + data.last_error, 'error');
                        } else {
                            showNotice('Queue completed! Processed ' + data.processed_count + ' profiles.', 'success');
                        }
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
    if (dhProfileQueue.isActive) {
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
});
