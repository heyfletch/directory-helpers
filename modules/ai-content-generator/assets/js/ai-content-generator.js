jQuery(document).ready(function ($) {
    const generateBtn = document.getElementById('dh-generate-ai-content');
    const keywordInput = document.getElementById('dh-ai-keyword');
    const statusDiv = document.getElementById('dh-ai-status');
    const postId = document.getElementById('post_ID').value;
    const photosBtn = document.getElementById('dh-unsplash-photos-btn');
    const createNotebookBtn = document.getElementById('dh-create-notebook');
    const replaceFeaturedImageBtn = document.getElementById('dh-replace-featured-image');

    if (!generateBtn && !photosBtn && !createNotebookBtn && !replaceFeaturedImageBtn) {
        return;
    }

    if (generateBtn) {
    generateBtn.addEventListener('click', function () {
        const originalKeyword = keywordInput.value;
        console.log('Original Keyword:', originalKeyword);

        // Sanitize the keyword:
        // 1) Convert hyphens to spaces
        // 2) Remove characters that are not letters, numbers, or spaces (handles quotes, brackets, punctuation, etc.)
        // 3) Collapse multiple spaces
        // 4) Trim leading/trailing spaces
        const sanitizedKeyword = originalKeyword
            .replace(/-/g, ' ')
            .replace(/[^\p{L}\p{N} ]+/gu, '')
            .replace(/\s+/g, ' ')
            .trim();
        console.log('Sanitized Keyword:', sanitizedKeyword);
        // Reflect the cleaned keyword back into the input so the user sees the final value
        keywordInput.value = sanitizedKeyword;

        const webhookUrl = aiContentGenerator.webhookUrl;

        if (!sanitizedKeyword) {
            statusDiv.textContent = 'Please enter a keyword.';
            statusDiv.style.color = 'red';
            return;
        }

        if (!webhookUrl) {
            statusDiv.textContent = 'Webhook URL is not configured in Directory Helpers settings.';
            statusDiv.style.color = 'red';
            return;
        }

        if (!window.confirm('Are you sure you want to generate AI content for this post?')) {
            statusDiv.textContent = 'Cancelled.';
            statusDiv.style.color = 'inherit';
            return;
        }

        // Clear any existing AI timestamp and status
        if (statusDiv) {
            statusDiv.textContent = 'Sending request...';
            statusDiv.style.color = 'inherit';
            // Also clear any existing timestamp in the UI
            const timestampEl = statusDiv.querySelector('.ai-timestamp');
            if (timestampEl) {
                timestampEl.remove();
            }
        }

        fetch(aiContentGenerator.triggerEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': aiContentGenerator.nonce,
            },
            body: JSON.stringify({ 
                postId: postId,
                keyword: sanitizedKeyword,
                target: 'ai'
            }),
        })
        .then(response => {
            if (response.ok) {
                const cssVar = getComputedStyle(document.documentElement).getPropertyValue('--wp-admin-theme-color--warning').trim();
                const warningColor = cssVar || '#ffcb09';
                statusDiv.textContent = '🟡 Request sent! The AI is generating content. Refresh this page in a few minutes to see the updated content.';
                statusDiv.style.color = warningColor;
                generateBtn.disabled = true;
            } else {
                // Try to parse the error response from n8n
                response.json().then(err => {
                    const errorMessage = err.message || 'An unknown error occurred.';
                    statusDiv.textContent = `Error: ${errorMessage}`;
                    statusDiv.style.color = 'red';
                }).catch(() => {
                    // Fallback if the response is not JSON
                    statusDiv.textContent = `Error: Request failed with status ${response.status} (${response.statusText}).`;
                    statusDiv.style.color = 'red';
                });
            }
        })
        .catch(error => {
            // Handle network errors (like CORS or DNS issues)
            console.error('Fetch Error:', error);
            statusDiv.textContent = 'Error: Failed to fetch. This may be a CORS issue or network problem. Check the browser console for more details.';
            statusDiv.style.color = 'red';
        });
    });
    }

    // Create Notebook button: trigger configured webhook with confirmation
    if (createNotebookBtn) {
        createNotebookBtn.addEventListener('click', function () {
            const originalKeyword = keywordInput ? keywordInput.value : '';

            const sanitizedKeyword = (originalKeyword || '')
                .replace(/-/g, ' ')
                .replace(/[^\p{L}\p{N} ]+/gu, '')
                .replace(/\s+/g, ' ')
                .trim();
            if (keywordInput) keywordInput.value = sanitizedKeyword;

            const notebookWebhookUrl = aiContentGenerator && aiContentGenerator.notebookWebhookUrl;

            if (!notebookWebhookUrl) {
                statusDiv.textContent = 'Notebook webhook URL is not configured in Directory Helpers settings.';
                statusDiv.style.color = 'red';
                return;
            }

            statusDiv.textContent = 'Sending request...';
            statusDiv.style.color = 'inherit';

            fetch(aiContentGenerator.triggerEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': aiContentGenerator.nonce,
                },
                body: JSON.stringify({
                    postId: postId,
                    keyword: sanitizedKeyword,
                    target: 'notebook'
                }),
            })
                .then(response => {
                    if (response.ok) {
                        statusDiv.innerHTML = '✅ Notebook creation triggered! Check your automations to monitor progress.';
                        statusDiv.style.color = 'green';
                        createNotebookBtn.disabled = true;
                    } else {
                        response.json().then(err => {
                            const errorMessage = err.message || 'An unknown error occurred.';
                            statusDiv.textContent = `Error: ${errorMessage}`;
                            statusDiv.style.color = 'red';
                        }).catch(() => {
                            statusDiv.textContent = `Error: Request failed with status ${response.status} (${response.statusText}).`;
                            statusDiv.style.color = 'red';
                        });
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    statusDiv.textContent = 'Error: Failed to fetch. Please make sure the Zerowork webhook is activated.';
                    statusDiv.style.color = 'red';
                });
        });
    }

    // Replace Featured Image button: trigger n8n workflow
    if (replaceFeaturedImageBtn) {
        replaceFeaturedImageBtn.addEventListener('click', function () {
            const featuredImageWebhookUrl = aiContentGenerator && aiContentGenerator.featuredImageWebhookUrl;

            if (!featuredImageWebhookUrl) {
                statusDiv.textContent = 'Featured Image webhook URL is not configured in Directory Helpers settings.';
                statusDiv.style.color = 'red';
                return;
            }

            statusDiv.textContent = 'Sending request...';
            statusDiv.style.color = 'inherit';
            replaceFeaturedImageBtn.disabled = true;

            // Get keyword from the input field
            const keyword = keywordInput ? keywordInput.value : '';

            fetch(aiContentGenerator.triggerEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': aiContentGenerator.nonce,
                },
                body: JSON.stringify({
                    postId: postId,
                    postTitle: aiContentGenerator.postTitle,
                    keyword: keyword,
                    target: 'featured-image'
                }),
            })
                .then(response => {
                    if (response.ok) {
                        const cssVar = getComputedStyle(document.documentElement).getPropertyValue('--wp-admin-theme-color--warning').trim();
                        const warningColor = cssVar || '#ffcb09';
                        statusDiv.textContent = '🟡 Request sent to n8n! Refresh this page in about 15 seconds to see the new image.';
                        statusDiv.style.color = warningColor;
                    } else {
                        response.json().then(err => {
                            const errorMessage = err.message || 'An unknown error occurred.';
                            statusDiv.textContent = `Error: ${errorMessage}`;
                            statusDiv.style.color = 'red';
                            replaceFeaturedImageBtn.disabled = false;
                        }).catch(() => {
                            statusDiv.textContent = `Error: Request failed with status ${response.status} (${response.statusText}).`;
                            statusDiv.style.color = 'red';
                            replaceFeaturedImageBtn.disabled = false;
                        });
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    statusDiv.textContent = 'Error: Failed to fetch. Please check the browser console for more details.';
                    statusDiv.style.color = 'red';
                    replaceFeaturedImageBtn.disabled = false;
                });
        });
    }

    // Unsplash Photos button: open Unsplash search for the area slug (without " - ST")
    if (photosBtn) {
        photosBtn.addEventListener('click', function () {
            // Try to read the current title from the editor (Classic). Fallback to localized title.
            const titleField = document.getElementById('title');
            const originalTitle = (titleField && titleField.value) || (aiContentGenerator && aiContentGenerator.postTitle) || '';

            // Sanitize the title similar to keyword cleaning:
            // 1) Convert hyphens to spaces
            // 2) Remove characters that are not letters, numbers, or spaces (strips commas, quotes, punctuation)
            // 3) Collapse multiple spaces
            // 4) Trim
            const sanitizedTitle = originalTitle
                .replace(/-/g, ' ')
                .replace(/[\p{L}\p{N} ]+/gu, (m) => m) // keep allowed runs
                .replace(/[^\p{L}\p{N} ]+/gu, '') // strip disallowed chars
                .replace(/\s+/g, ' ')
                .trim();

            // Prefer server-provided slug derived from area term (city-listing only)
            const providedSlug = (typeof aiContentGenerator !== 'undefined' && aiContentGenerator.unsplashSlug) ? aiContentGenerator.unsplashSlug : '';
            const fallbackSlug = sanitizedTitle ? sanitizedTitle.toLowerCase().replace(/\s+/g, '-') : '';
            const slug = providedSlug || fallbackSlug;

            let url = 'https://unsplash.com/s/photos';
            if (slug) {
                url += '/' + encodeURIComponent(slug);
            }
            url += '?license=free&orientation=landscape';
            window.open(url, '_blank', 'noopener');
        });
    }

    // Heartbeat: check for AI content updates
    (function initHeartbeatNotify(){
        if (typeof wp === 'undefined' || !wp || !wp.heartbeat || !postId) { return; }
        var storageKey = 'dh_ai_last_reload_' + String(postId);
        var lastSeen = 0; // server comparison hint (optional)

        // Send a small payload on each heartbeat
        $(document).on('heartbeat-send', function (e, data) {
            data.dh_ai_check = {
                postId: parseInt(postId, 10) || 0,
                lastSeen: lastSeen
            };
        });

        // Receive server response
        $(document).on('heartbeat-tick', function (e, data) {
            if (!data || !data.dh_ai) { return; }
            var serverTs = parseInt(data.dh_ai.timestamp || 0, 10) || 0;
            if (!data.dh_ai.updated || !serverTs) { return; }

            var storedTs = parseInt(localStorage.getItem(storageKey) || '0', 10) || 0;
            if (serverTs > storedTs) {
                // Persist before reload to avoid a second reload on page return
                try { localStorage.setItem(storageKey, String(serverTs)); } catch(_) {}
                lastSeen = serverTs;

                // Style and icon for visibility
                try {
                    var cssVar = getComputedStyle(document.documentElement).getPropertyValue('--wp-admin-theme-color--warning').trim();
                    var warningColor = cssVar || '#ffcb09';
                    if (statusDiv) {
                        statusDiv.innerHTML = '<span class="dashicons dashicons-update" style="vertical-align:middle;margin-right:6px;color:' + warningColor + ';"></span>' +
                                              '<strong style="vertical-align:middle;color:' + warningColor + ';">AI content received. Reloading…</strong>';
                    }
                } catch(_) {}

                window.location.reload();
            }
        });
    })();
});
