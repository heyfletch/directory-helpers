jQuery(document).ready(function ($) {
    const generateBtn = document.getElementById('dh-generate-ai-content');
    const keywordInput = document.getElementById('dh-ai-keyword');
    const statusDiv = document.getElementById('dh-ai-status');
    const postId = document.getElementById('post_ID').value;

    if (!generateBtn) {
        return;
    }

    generateBtn.addEventListener('click', function () {
        const originalKeyword = keywordInput.value;
        console.log('Original Keyword:', originalKeyword);

        // Sanitize the keyword to remove special characters like colons, quotes, brackets, and commas.
        const sanitizedKeyword = originalKeyword.replace(/[\[\]":,]/g, '');
        console.log('Sanitized Keyword:', sanitizedKeyword);

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
});
