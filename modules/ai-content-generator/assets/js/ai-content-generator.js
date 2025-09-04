jQuery(document).ready(function ($) {
    const generateBtn = document.getElementById('dh-generate-ai-content');
    const keywordInput = document.getElementById('dh-ai-keyword');
    const statusDiv = document.getElementById('dh-ai-status');
    const postId = document.getElementById('post_ID').value;
    const photosBtn = document.getElementById('dh-unsplash-photos-btn');
    const createNotebookBtn = document.getElementById('dh-create-notebook');

    if (!generateBtn && !photosBtn && !createNotebookBtn) {
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

            if (!window.confirm('Create Notebook?')) {
                statusDiv.textContent = 'Cancelled.';
                statusDiv.style.color = 'inherit';
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

    // Heartbeat: auto-reload when AI content lands
    (function initHeartbeatNotify(){
        if (typeof wp === 'undefined' || !wp || !wp.heartbeat || !postId) { return; }
        var lastSeen = 0;
        var reloaded = false;
        // Send a small payload on each heartbeat
        $(document).on('heartbeat-send', function (e, data) {
            data.dh_ai_check = { postId: parseInt(postId, 10) || 0, lastSeen: lastSeen };
        });
        // Receive server response
        $(document).on('heartbeat-tick', function (e, data) {
            if (!data || !data.dh_ai) { return; }
            if (data.dh_ai.updated) {
                lastSeen = data.dh_ai.timestamp || Date.now();
                if (!reloaded) {
                    reloaded = true;
                    try {
                        if (statusDiv) {
                            statusDiv.textContent = 'AI content received. Reloading…';
                        }
                    } catch(_) {}
                    window.location.reload();
                }
            }
        });
    })();
});
