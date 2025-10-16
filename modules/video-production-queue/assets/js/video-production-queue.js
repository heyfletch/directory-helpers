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
                    
                    // Start video processing
                    startVideoProcessing();
                    
                    // Don't reload - let processing continue
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
                    
                    // Stop processing
                    stopVideoProcessing();
                    
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
     * Video processing and status polling
     */
    let processingInterval = null;
    
    function startVideoProcessing() {
        if (processingInterval) {
            return;
        }
        
        // Process immediately, then every 30 seconds
        processNextVideo();
        
        processingInterval = setInterval(function() {
            processNextVideo();
        }, 30000); // Check every 30 seconds (videos take ~10 minutes)
    }
    
    function stopVideoProcessing() {
        if (processingInterval) {
            clearInterval(processingInterval);
            processingInterval = null;
        }
    }
    
    function processNextVideo() {
        $.ajax({
            url: dhVideoQueue.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_process_video_next',
                nonce: dhVideoQueue.nonce
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    // Update current post display
                    if (data.current_post_title) {
                        $('#dh-current-post-title').html('<a href="' + data.current_post_link + '" target="_blank">' + data.current_post_title + '</a>');
                        $('#dh-current-processing').show();
                    } else {
                        $('#dh-current-processing').hide();
                    }
                    
                    // Show waiting message if applicable
                    if (data.waiting) {
                        console.log('Waiting for video to complete: ' + data.current_post_title);
                    }
                    
                    // If queue is no longer active, stop processing and reload
                    if (!data.is_active) {
                        stopVideoProcessing();
                        if (data.last_error) {
                            showNotice('Queue stopped: ' + data.last_error, 'error');
                        } else {
                            showNotice('Queue completed!', 'success');
                        }
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    }
                }
            },
            error: function() {
                // On error, just continue - cron will pick it up
                console.log('Error processing video, cron will retry');
            }
        });
    }
    
    // Start processing if queue is active on page load
    if (dhVideoQueue.isActive) {
        startVideoProcessing();
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
