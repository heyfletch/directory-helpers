jQuery(document).ready(function ($) {
    const generateBtn = document.getElementById('dh-generate-ai-content');
    const keywordInput = document.getElementById('dh-ai-keyword');
    const statusDiv = document.getElementById('dh-ai-status');
    const postId = document.getElementById('post_ID').value;
    const photosBtn = document.getElementById('dh-unsplash-photos-btn');

    if (!generateBtn && !photosBtn) {
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

        statusDiv.textContent = 'Sending request...';
        statusDiv.style.color = 'inherit';

        fetch(webhookUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ 
                postId: postId,
                keyword: sanitizedKeyword 
            }),
        })
        .then(response => {
            if (response.ok) {
                statusDiv.innerHTML = '✅ Request sent! The AI is generating content. Refresh this page in a few minutes to see the updated content.';
                statusDiv.style.color = 'green';
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

    // Unsplash Photos button: build a sanitized query from the post title and open Unsplash search
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

            if (!sanitizedTitle) {
                window.open('https://unsplash.com/s/photos', '_blank', 'noopener');
                return;
            }

            const url = 'https://unsplash.com/s/photos/' + encodeURIComponent(sanitizedTitle);
            window.open(url, '_blank', 'noopener');
        });
    }
});
