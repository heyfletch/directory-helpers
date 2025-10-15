# Replace Featured Image Button - Setup Instructions

## What Was Implemented

A new "Replace Featured Image" button in the AI Content Generator meta box on city-listing and state-listing post edit screens. The button triggers your n8n workflow to generate and replace the featured image.

---

## Step-by-Step: Configure n8n Workflow

### Step 1: Add Webhook Node to Your Workflow

1. Open your Featured Image workflow in n8n
2. **Delete or disconnect** the "On form submission" node (you can keep the form for manual testing if you want)
3. Add a new **Webhook** node at the start of your workflow
4. Configure the Webhook node:
   - **HTTP Method**: POST
   - **Path**: `3c1f4f7c-53e0-481d-b3b6-8ac2645abf45` (use your existing webhook ID)
   - **Response Mode**: "Response Node"
   - **Authentication**: None

### Step 2: Add CORS Handling (Same as AI Content Workflow)

Add an **IF** node after the Webhook:
- **Condition**: `{{ $request.method }}` equals `OPTIONS`

**True branch** (OPTIONS request):
- Add "Respond to Webhook" node
- Set Response Code: 204
- Add Response Headers:
  - `Access-Control-Allow-Origin`: `https://goodydoggy.com`
  - `Access-Control-Allow-Methods`: `POST, OPTIONS`
  - `Access-Control-Allow-Headers`: `Content-Type`

**False branch** (POST request):
- Add "Respond to Webhook" node
- Response: JSON
- Body: `{"status": "request received"}`
- Response Code: 200

### Step 3: Update setInputs Node

After the response node, connect to your **setInputs** node and update it:

```javascript
{
  "assignments": {
    "assignments": [
      {
        "id": "7fcc90a6-db0c-4742-afce-688fa871680e",
        "name": "keyword",
        "value": "={{ $json.body.keyword || 'dog training in ' + $json.body.postTitle.replace(/^Private: /, '') }}",
        "type": "string"
      },
      {
        "id": "c6e66037-2da3-4df2-8395-6eedc54fa3b5",
        "name": "postId",
        "value": "={{ $json.body.postId }}",
        "type": "number"
      },
      {
        "id": "7eaa3a7c-125b-46ce-97a8-03496de4dc62",
        "name": "postTitle",
        "value": "={{ $json.body.postTitle.replace(/^Private: /, '') }}",
        "type": "string"
      }
    ]
  }
}
```

**Key changes:**
- Changed from `$json.Location` to `$json.body.postTitle`
- Changed from `$json.PostID` to `$json.body.postId`
- Added `$json.body.keyword` with fallback to constructed keyword

### Step 4: Connect the Flow

Your workflow should now look like:

```
Webhook → IF (check OPTIONS) → checkCORS (if OPTIONS)
                              → sendOKstatus (if POST) → setInputs → FeaturedImage → ...rest of workflow
```

### Step 5: Test the Webhook

Use the test URL to verify:
```bash
curl -X POST https://flow.pressento.com/webhook-test/3c1f4f7c-53e0-481d-b3b6-8ac2645abf45 \
  -H "Content-Type: application/json" \
  -d '{
    "postId": 12345,
    "postTitle": "Burlington, VT",
    "keyword": "dog training in Burlington VT"
  }'
```

You should see the workflow execute with the correct data in setInputs.

---

## Step-by-Step: Configure WordPress

### Step 1: Add Webhook URL to Settings

1. Go to **WordPress Admin** → **Directory Helpers** → **Settings**
2. Find the **"Featured Image Webhook URL"** field
3. Enter your **production** webhook URL:
   ```
   https://flow.pressento.com/webhook/3c1f4f7c-53e0-481d-b3b6-8ac2645abf45
   ```
4. Click **Save Settings**

### Step 2: Test the Button

1. Edit any city-listing or state-listing post
2. Look for the **"AI Content Generator"** meta box (usually in the right sidebar)
3. You should see the **"Replace Featured Image"** button below the "Create Notebook" button
4. Click the button
5. Confirm the dialog
6. You should see: "🟡 Request sent! The featured image is being generated..."

### Step 3: Verify the Workflow

1. Check your n8n workflow executions
2. Verify the webhook received:
   - `postId`: The WordPress post ID
   - `postTitle`: The post title (e.g., "Burlington, VT")
   - `keyword`: Either the ACF keyword field value OR the keyword input field value

---

## Data Flow

### WordPress sends to n8n:
```json
{
  "postId": 12345,
  "postTitle": "Burlington, VT",
  "keyword": "dog training in Burlington VT"
}
```

### n8n workflow:
1. Generates featured image using Imagen/Gemini
2. Uploads to WordPress media library
3. Sets alt text and title
4. Calls `/receive-featured-media` endpoint with:
   ```json
   {
     "postId": 12345,
     "secretKey": "your-secret-key",
     "featured_media": 67890
   }
   ```

### WordPress receives and:
1. Sets the featured image on the post
2. Returns success response

---

## Troubleshooting

### Button doesn't appear
- Clear browser cache
- Check that you're editing a city-listing or state-listing post
- Verify the JavaScript file was updated (check file timestamp)

### "Webhook URL not configured" error
- Go to Directory Helpers settings
- Add the webhook URL
- Save settings

### Workflow doesn't trigger
- Check browser console for errors
- Verify the webhook URL is correct
- Test the webhook directly with curl
- Check n8n workflow is activated

### Featured image doesn't update
- Check n8n workflow completed successfully
- Verify the `postToWordPress` node is calling the correct endpoint
- Check WordPress error logs
- Verify the secret key matches in both places

---

## Optional: Keep the Form for Manual Testing

You can keep both the form trigger AND the webhook trigger in the same workflow:

1. Keep the "On form submission" node
2. Add a **Merge** node after both triggers
3. Connect both the form and webhook to the merge node
4. Connect merge node to setInputs

This way you can still manually test via the form URL when needed.

---

## Summary

**Button location**: AI Content Generator meta box  
**Sends**: postId, postTitle, keyword (from ACF field or input)  
**Webhook endpoint**: `/webhook/3c1f4f7c-53e0-481d-b3b6-8ac2645abf45`  
**Final endpoint**: `/wp-json/ai-content-plugin/v1/receive-featured-media`  
**Required fields**: secretKey, postId, featured_media
