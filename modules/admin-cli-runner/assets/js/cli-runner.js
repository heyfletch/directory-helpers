(function($) {
    'use strict';

    var pollInterval = null;
    var isPolling = false;

    /**
     * Initialize CLI runner buttons
     */
    function init() {
        // Run command buttons
        $(document).on('click', '.dh-cli-run-btn', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var command = $btn.data('command') || $btn.data('command-template');
            
            if (!command) {
                alert('No command specified');
                return;
            }

            runCommand($btn, command);
        });

        // Stop command button
        $(document).on('click', '.dh-cli-stop-btn', function(e) {
            e.preventDefault();
            if (confirm(dhCliRunner.strings.confirm_stop)) {
                stopCommand();
            }
        });

        // Start polling if a command is running
        checkInitialStatus();
    }

    /**
     * Run a CLI command
     */
    function runCommand($btn, command) {
        var $status = $btn.siblings('.dh-cli-status');
        
        // If command is a template, replace {niche} with selected niche
        if (command.indexOf('{niche}') !== -1) {
            var selectedNiche = $('#dh-cli-niche-select').val() || 'dog-trainer';
            command = command.replace('{niche}', selectedNiche);
        }
        
        $btn.prop('disabled', true);
        $status.html('<span class="spinner is-active" style="float: none; margin: 0 5px;"></span> ' + dhCliRunner.strings.running);

        $.ajax({
            url: dhCliRunner.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_run_cli_command',
                nonce: dhCliRunner.nonce,
                command: command
            },
            success: function(response) {
                if (response.success) {
                    updateGlobalStatus(command, 'running');
                    startPolling();
                } else {
                    $btn.prop('disabled', false);
                    $status.html('<span style="color: #dc3232;">❌ ' + (response.data.message || dhCliRunner.strings.failed) + '</span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $status.html('<span style="color: #dc3232;">❌ ' + dhCliRunner.strings.failed + '</span>');
            }
        });
    }

    /**
     * Stop running command
     */
    function stopCommand() {
        $.ajax({
            url: dhCliRunner.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_stop_cli_command',
                nonce: dhCliRunner.nonce
            },
            success: function(response) {
                stopPolling();
                updateGlobalStatus('', 'stopped');
                $('.dh-cli-run-btn').prop('disabled', false);
                $('.dh-cli-status').html('<span style="color: #f0ad4e;">⚠️ ' + dhCliRunner.strings.stopped + '</span>');
            }
        });
    }

    /**
     * Check initial status on page load
     */
    function checkInitialStatus() {
        $.ajax({
            url: dhCliRunner.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_get_cli_status',
                nonce: dhCliRunner.nonce
            },
            success: function(response) {
                if (response.success && response.data.running) {
                    startPolling();
                }
            }
        });
    }

    /**
     * Start polling for status updates
     */
    function startPolling() {
        if (isPolling) return;
        isPolling = true;
        
        // Show log box
        $('#dh-cli-log-box').show();

        pollInterval = setInterval(function() {
            $.ajax({
                url: dhCliRunner.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dh_get_cli_status',
                    nonce: dhCliRunner.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update log display
                        if (response.data.log) {
                            $('#dh-cli-log-output').text(response.data.log);
                            // Auto-scroll to bottom
                            var logBox = document.getElementById('dh-cli-log-box');
                            if (logBox) {
                                logBox.scrollTop = logBox.scrollHeight;
                            }
                        }
                        
                        // Update status
                        if (response.data.status === 'running') {
                            updateGlobalStatus(response.data.command, 'running');
                        } else if (response.data.status === 'completed') {
                            stopPolling();
                            updateGlobalStatus('', 'completed');
                            $('.dh-cli-run-btn').prop('disabled', false);
                            $('.dh-cli-status').html('<span style="color: #46b450;">✓ ' + dhCliRunner.strings.completed + '</span>');
                        }
                    }
                }
            });
        }, 2000); // Poll every 2 seconds
    }

    /**
     * Stop polling
     */
    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
        isPolling = false;
    }

    /**
     * Update global status display
     */
    function updateGlobalStatus(command, status) {
        var $globalStatus = $('#dh-cli-global-status');
        var $stopBtn = $('.dh-cli-stop-btn');

        if (status === 'running' && command) {
            $globalStatus.html('<span style="color: #0073aa;">⏳ ' + command + '</span>');
            if ($stopBtn.length === 0) {
                $globalStatus.after('<button type="button" class="button button-small dh-cli-stop-btn" style="margin-left: 10px;">Stop</button>');
            }
        } else if (status === 'completed') {
            $globalStatus.html('<span style="color: #46b450;">✓ Ready</span>');
            $stopBtn.remove();
        } else if (status === 'stopped') {
            $globalStatus.html('<span style="color: #f0ad4e;">⚠️ Stopped</span>');
            $stopBtn.remove();
        } else {
            $globalStatus.html('<span style="color: #46b450;">✓ Ready</span>');
            $stopBtn.remove();
        }
    }

    // Initialize on document ready
    $(document).ready(init);

})(jQuery);
