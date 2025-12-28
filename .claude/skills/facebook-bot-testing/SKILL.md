---
name: facebook-bot-testing
description: Test Facebook bot flows using Playwright MCP, verify chat responses, and validate AI pipeline (decision model, knowledge base, chat model). Use when testing bot flows, verifying responses, checking flow editor, or debugging bot behavior.
---

# Facebook Bot Testing

## Bot Pipeline Overview

```
User Message → Decision Model → Knowledge Base (optional) → Chat Model → Response
                    ↓                    ↓                       ↓
              Intent + Confidence   RAG Results           Final Answer
```

---

## Quick Test via UI

### 1. Navigate to Flow Editor
```
URL: https://frontend-production-9fe8.up.railway.app/flows/{flowId}/edit?botId={botId}
Example: https://frontend-production-9fe8.up.railway.app/flows/13/edit?botId=15
```

### 2. Use Test Panel
- Find "Test" section on the right side
- Enter test message
- Click "Send" button
- Check execution trace for each stage

### 3. Verify Response
- Decision Model: Check intent classification
- Knowledge Base: Check if documents retrieved (if enabled)
- Chat Model: Check final response quality

---

## Test via API

```bash
# Test stream endpoint
curl -X POST https://backend-production-b216.up.railway.app/api/stream \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "message": "สวัสดี",
    "bot_id": 15,
    "flow_id": 13
  }'
```

---

## Playwright MCP Testing

### Basic Flow Test
```javascript
// Navigate to flow editor
await page.goto('https://frontend-production-9fe8.up.railway.app/flows/13/edit?botId=15');

// Wait for page load
await page.waitForSelector('[data-testid="flow-editor"]');

// Find test input
const testInput = page.getByRole('textbox', { name: /message/i });
await testInput.fill('สวัสดี');

// Submit and wait for response
await page.getByRole('button', { name: /send/i }).click();
await page.waitForSelector('[data-testid="response"]');
```

---

## What to Verify

| Stage | Check |
|-------|-------|
| Decision Model | Intent correct? Confidence > 50%? |
| Knowledge Base | Relevant docs retrieved? (if enabled) |
| Chat Model | Response appropriate? Language correct? |
| Latency | Total time < 10 seconds? |
| Tokens | Usage reasonable? |

---

## Common Issues

| Issue | Possible Cause | Fix |
|-------|---------------|-----|
| 500 error | Service not configured | Check API keys in env |
| Timeout | Model slow | Check OpenRouter status |
| Wrong language | Prompt issue | Review system prompt |
| No KB results | Embeddings missing | Reindex documents |

---

## Test Scenarios

### 1. Basic Greeting (Thai)
```
Input: สวัสดี
Expected: Thai greeting response
Check: Decision = "chat", Response in Thai
```

### 2. FAQ Query
```
Input: ราคาเท่าไหร่
Expected: Price information (if in KB)
Check: KB retrieval works, Response accurate
```

### 3. Unknown Intent
```
Input: abcdefg
Expected: Fallback response
Check: Graceful handling
```

---

## Production Bot Info

| Field | Value |
|-------|-------|
| Bot ID | 15 |
| Flow ID | 13 |
| Decision Model | gpt-4o-mini |
| Chat Model | (per flow config) |
