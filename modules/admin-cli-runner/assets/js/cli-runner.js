(function($) {
    'use strict';

    var pollInterval  = null;
    var isPolling     = false;

    // Map of job_id -> { $btn, $status } so we can update the right row.
    var jobButtonMap  = {};

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    function init() {
        $(document).on('click', '.dh-cli-run-btn', function(e) {
            e.preventDefault();
            var $btn     = $(this);
            var command  = $btn.data('command') || $btn.data('command-template');

            if (!command) {
                alert('No command specified');
                return;
            }

            // Replace {niche} placeholder if present.
            if (command.indexOf('{niche}') !== -1) {
                var selectedNiche = $('#dh-cli-niche-select').val() || 'dog-trainer';
                command = command.replace('{niche}', selectedNiche);
            }

            runCommand($btn, command);
        });

        $(document).on('click', '.dh-cli-stop-btn', function(e) {
            e.preventDefault();
            var jobId = $(this).data('job-id');
            if (jobId) {
                if (confirm(dhCliRunner.strings.confirm_stop)) {
                    stopCommand(jobId, false);
                }
            }
        });

        $(document).on('click', '.dh-cli-stop-all-btn', function(e) {
            e.preventDefault();
            if (confirm(dhCliRunner.strings.confirm_stop_all)) {
                stopCommand('', true);
            }
        });

        checkInitialStatus();
    }

    // -------------------------------------------------------------------------
    // Run command
    // -------------------------------------------------------------------------

    function runCommand($btn, command) {
        var $status = $btn.siblings('.dh-cli-status');

        $btn.prop('disabled', true);
        $status.html('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>' +
                     dhCliRunner.strings.queued);

        $.ajax({
            url:  dhCliRunner.ajaxUrl,
            type: 'POST',
            data: {
                action:  'dh_run_cli_command',
                nonce:   dhCliRunner.nonce,
                command: command
            },
            success: function(response) {
                if (response.success) {
                    var jobId  = response.data.job_id;
                    var status = response.data.status; // 'queued' or 'running'

                    // Remember which button owns this job.
                    jobButtonMap[jobId] = { $btn: $btn, $status: $status };

                    setButtonStatus($btn, $status, status, jobId);
                    renderQueuePanel(response.data.queue);
                    startPolling();
                } else {
                    $btn.prop('disabled', false);
                    $status.html('<span style="color:#dc3232;">&#10007; ' +
                                 escHtml(response.data.message || dhCliRunner.strings.failed) +
                                 '</span>');
                }
            },
            error: function() {
                $btn.prop('disabled', false);
                $status.html('<span style="color:#dc3232;">&#10007; ' +
                             dhCliRunner.strings.failed + '</span>');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Stop command(s)
    // -------------------------------------------------------------------------

    function stopCommand(jobId, stopAll) {
        $.ajax({
            url:  dhCliRunner.ajaxUrl,
            type: 'POST',
            data: {
                action:   'dh_stop_cli_command',
                nonce:    dhCliRunner.nonce,
                job_id:   jobId,
                stop_all: stopAll ? 1 : 0
            },
            success: function(response) {
                if (response.success) {
                    renderQueuePanel(response.data.queue);
                    applyQueueToButtons(response.data.queue);

                    var hasActive = false;
                    $.each(response.data.queue, function(_, job) {
                        if (job.status === 'queued' || job.status === 'running') {
                            hasActive = true;
                        }
                    });
                    if (!hasActive) {
                        stopPolling();
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // Polling
    // -------------------------------------------------------------------------

    function checkInitialStatus() {
        $.ajax({
            url:  dhCliRunner.ajaxUrl,
            type: 'POST',
            data: {
                action: 'dh_get_cli_status',
                nonce:  dhCliRunner.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderQueuePanel(response.data.queue);
                    applyQueueToButtons(response.data.queue);
                    if (response.data.has_active) {
                        startPolling();
                    }
                }
            }
        });
    }

    function startPolling() {
        if (isPolling) return;
        isPolling    = true;
        $('#dh-cli-log-box').show();

        pollInterval = setInterval(function() {
            $.ajax({
                url:  dhCliRunner.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dh_get_cli_status',
                    nonce:  dhCliRunner.nonce
                },
                success: function(response) {
                    if (!response.success) return;

                    renderQueuePanel(response.data.queue);
                    applyQueueToButtons(response.data.queue);

                    if (!response.data.has_active) {
                        stopPolling();
                    }
                }
            });
        }, 2000);
    }

    function stopPolling() {
        if (pollInterval) {
            clearInterval(pollInterval);
            pollInterval = null;
        }
        isPolling = false;
    }

    // -------------------------------------------------------------------------
    // UI helpers
    // -------------------------------------------------------------------------

    /**
     * Update a single button's inline status indicator.
     */
    function setButtonStatus($btn, $status, status, jobId) {
        var stopHtml = jobId
            ? ' <button type="button" class="button button-small dh-cli-stop-btn" ' +
              'data-job-id="' + escHtml(jobId) + '" style="margin-left:6px;">Stop</button>'
            : '';

        switch (status) {
            case 'running':
                $btn.prop('disabled', true);
                $status.html('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>' +
                             '<span style="color:#0073aa;">' + dhCliRunner.strings.running + '</span>' +
                             stopHtml);
                break;
            case 'queued':
                $btn.prop('disabled', true);
                $status.html('<span class="spinner is-active" style="float:none;margin:0 5px;"></span>' +
                             '<span style="color:#666;">' + dhCliRunner.strings.queued + '</span>' +
                             stopHtml);
                break;
            case 'completed':
                $btn.prop('disabled', false);
                $status.html('<span style="color:#46b450;">&#10003; ' + dhCliRunner.strings.completed + '</span>');
                break;
            case 'failed':
                $btn.prop('disabled', false);
                $status.html('<span style="color:#dc3232;">&#10007; ' + dhCliRunner.strings.failed + '</span>');
                break;
            case 'stopped':
                $btn.prop('disabled', false);
                $status.html('<span style="color:#f0ad4e;">&#9888; ' + dhCliRunner.strings.stopped + '</span>');
                break;
        }
    }

    /**
     * Walk the returned queue and update any buttons we're tracking.
     * Also reload term pages when an analyze-radius job finishes.
     */
    function applyQueueToButtons(queue) {
        $.each(queue, function(_, job) {
            var entry = jobButtonMap[job.id];
            if (!entry) return;

            setButtonStatus(entry.$btn, entry.$status, job.status, job.id);

            // Auto-reload term edit page after analyze-radius completes.
            if ((job.status === 'completed') &&
                job.command.indexOf('analyze-radius') !== -1 &&
                window.location.href.indexOf('term.php') !== -1) {
                setTimeout(function() { window.location.reload(); }, 1000);
            }

            // Remove from map once terminal state is reached.
            if (['completed', 'failed', 'stopped'].indexOf(job.status) !== -1) {
                delete jobButtonMap[job.id];
            }
        });
    }

    /**
     * Render (or update) the global queue panel / log box.
     * Only shows active (queued/running) jobs — completed/failed/stopped jobs
     * are handled inline by applyQueueToButtons and never shown in the panel.
     */
    function renderQueuePanel(queue) {
        var $panel = $('#dh-cli-queue-panel');

        // Filter to only active jobs.
        var activeJobs = [];
        var latestRunning = null;
        $.each(queue, function(_, job) {
            if (job.status === 'queued' || job.status === 'running') {
                activeJobs.push(job);
                if (job.status === 'running') latestRunning = job;
            }
        });

        // If nothing active, hide and clear the panel entirely.
        if (activeJobs.length === 0) {
            if ($panel.length) $panel.html('').hide();
            return;
        }

        if (!$panel.length) {
            var $anchor = $('.dh-cli-actions').first().closest('td');
            if (!$anchor.length) $anchor = $('.dh-cli-actions').first().parent();
            $anchor.append('<div id="dh-cli-queue-panel" style="margin-top:12px;"></div>');
            $panel = $('#dh-cli-queue-panel');
        }
        $panel.show();

        var rows = '';
        $.each(activeJobs, function(_, job) {
            var badge   = statusBadge(job.status);
            var stopBtn = '<button type="button" class="button button-small dh-cli-stop-btn" ' +
                          'data-job-id="' + escHtml(job.id) + '" style="margin-left:6px;">Stop</button>';
            rows += '<tr>' +
                '<td style="padding:4px 8px;font-family:monospace;">' + escHtml(job.command) + '</td>' +
                '<td style="padding:4px 8px;white-space:nowrap;">' + badge + stopBtn + '</td>' +
                '</tr>';
        });

        var stopAllBtn = activeJobs.length > 1
            ? '<button type="button" class="button button-small dh-cli-stop-all-btn" ' +
              'style="margin-bottom:6px;">Stop All</button> '
            : '';

        var logHtml = '';
        if (latestRunning && latestRunning.log) {
            logHtml = '<div id="dh-cli-log-output" style="margin-top:8px;background:#1d1d1d;color:#eee;' +
                      'padding:10px;font-family:monospace;font-size:12px;max-height:200px;overflow-y:auto;' +
                      'white-space:pre-wrap;border-radius:4px;">' +
                      escHtml(latestRunning.log) + '</div>';
        }

        $panel.html(
            stopAllBtn +
            '<table style="width:100%;border-collapse:collapse;border:1px solid #ccd0d4;">' +
            '<thead><tr>' +
            '<th style="padding:4px 8px;background:#f1f1f1;text-align:left;">Command</th>' +
            '<th style="padding:4px 8px;background:#f1f1f1;text-align:left;">Status</th>' +
            '</tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
            '</table>' +
            logHtml
        );

        // Auto-scroll log.
        var logEl = document.getElementById('dh-cli-log-output');
        if (logEl) logEl.scrollTop = logEl.scrollHeight;
    }

    function statusBadge(status) {
        var colors = {
            queued:    '#888',
            running:   '#0073aa',
            completed: '#46b450',
            failed:    '#dc3232',
            stopped:   '#f0ad4e'
        };
        var labels = {
            queued:    'Queued',
            running:   'Running',
            completed: 'Completed',
            failed:    'Failed',
            stopped:   'Stopped'
        };
        var c = colors[status] || '#888';
        var l = labels[status] || status;
        return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;' +
               'background:' + c + ';color:#fff;font-size:11px;">' + escHtml(l) + '</span>';
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Initialize on document ready.
    $(document).ready(init);

})(jQuery);
