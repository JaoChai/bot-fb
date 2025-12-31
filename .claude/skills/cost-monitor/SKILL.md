---
name: cost-monitor
description: ติดตามและ optimize LLM costs - ใช้เมื่อต้องการคำนวณ cost, เปรียบเทียบราคา models, หรือหาวิธีลด token usage
---

# Cost Monitor

ใช้ skill นี้เพื่อติดตามและ optimize ค่าใช้จ่าย LLM

## Model Pricing Comparison (OpenRouter)

### Chat Models (Dec 2025)

| Model | Input $/1M | Output $/1M | Speed | Use Case |
|-------|------------|-------------|-------|----------|
| **Claude 3.5 Sonnet** | $3.00 | $15.00 | Medium | Main chat, complex tasks |
| **Claude 3.5 Haiku** | $0.80 | $4.00 | Fast | Decision model, simple tasks |
| **GPT-4o** | $2.50 | $10.00 | Medium | Alternative main model |
| **GPT-4o-mini** | $0.15 | $0.60 | Fast | Decision model, fallback |
| **Gemini 2.0 Flash** | $0.10 | $0.40 | Very Fast | Low-cost alternative |

### Embedding Models

| Model | $/1M tokens | Dimensions | Notes |
|-------|-------------|------------|-------|
| **text-embedding-3-small** | $0.02 | 1536 | Default, good balance |
| **text-embedding-3-large** | $0.13 | 3072 | Higher quality |

### Reranking Models

| Model | Price | Notes |
|-------|-------|-------|
| **Jina Reranker** | $0.02/1000 pairs | Used in hybrid search |

ดู `data/model-pricing.csv` สำหรับรายละเอียด

---

## Cost Estimation

### Per Conversation Estimate

**Typical conversation (5 turns):**
```
Decision Model (5x): ~500 tokens input, ~100 tokens output
Chat Model (5x): ~2000 tokens input, ~500 tokens output
Embeddings: ~200 tokens (5 queries)

Cost breakdown (Claude 3.5 Sonnet + Haiku):
- Decision: 500 * 5 * $0.80/1M + 100 * 5 * $4.00/1M = $0.004
- Chat: 2000 * 5 * $3.00/1M + 500 * 5 * $15.00/1M = $0.068
- Embeddings: 200 * 5 * $0.02/1M = $0.00002
- Total: ~$0.07/conversation
```

### Monthly Cost Projection

| Daily Conversations | Cost/Conv | Daily Cost | Monthly Cost |
|---------------------|-----------|------------|--------------|
| 100 | $0.07 | $7 | $210 |
| 500 | $0.07 | $35 | $1,050 |
| 1,000 | $0.07 | $70 | $2,100 |
| 5,000 | $0.07 | $350 | $10,500 |

---

## Cost Optimization Strategies

### 1. Use Smaller Models for Simple Tasks

**Before:**
```php
// ใช้ Claude 3.5 Sonnet สำหรับทุกอย่าง
'decision_model' => 'anthropic/claude-3.5-sonnet'
'chat_model' => 'anthropic/claude-3.5-sonnet'
```

**After:**
```php
// ใช้ model เหมาะกับงาน
'decision_model' => 'anthropic/claude-3.5-haiku' // ถูกกว่า 4x
'chat_model' => 'anthropic/claude-3.5-sonnet'    // quality ดี
```

**Savings:** ~30-40% ของ total cost

### 2. Reduce Context Window

**Before:**
```php
'kb_max_results' => 10  // ส่ง 10 chunks ให้ LLM
```

**After:**
```php
'kb_max_results' => 5   // ลดเหลือ 5 chunks
```

**Savings:** ลด input tokens ~50%

### 3. Shorter System Prompts

**Before:** 500+ words system prompt
**After:** 150-200 words system prompt

**Savings:** ลด input tokens per request

### 4. Use Caching

- Cache embeddings (already implemented)
- Cache frequent responses
- Cache user profiles

### 5. Set Max Tokens Limit

```php
'max_tokens' => 1024  // ไม่ให้ response ยาวเกินไป
```

---

## Token Counting

### Rough Estimates
- 1 word (English) ≈ 1.3 tokens
- 1 word (Thai) ≈ 2-3 tokens (Thai ใช้ tokens มากกว่า)
- 1 character (Thai) ≈ 0.5-1 token

### Check Actual Usage
```php
// ดู token usage จาก OpenRouter response
$response['usage']['prompt_tokens']
$response['usage']['completion_tokens']
```

### Log Token Usage
```bash
grep "tokens" storage/logs/laravel.log | tail -20
```

---

## Cost Alerts

### Manual Monitoring
1. ดู OpenRouter Dashboard: https://openrouter.ai/keys
2. Set spending limit ใน OpenRouter
3. Monitor daily usage

### Set Budget Limits
```
OpenRouter > API Keys > Set Limit
```

---

## Evaluation Cost Estimate

### Per Evaluation Run (40 test cases)

| Component | Tokens | Cost (Claude 3.5) |
|-----------|--------|-------------------|
| Test Generation | ~30,000 | $0.45 |
| Conversation Simulation | ~80,000 | $1.20 |
| LLM Judging | ~60,000 | $0.90 |
| Report Generation | ~20,000 | $0.30 |
| **Total** | ~190,000 | **~$2.85** |

### Monthly Evaluation Budget
| Frequency | Runs/Month | Cost/Month |
|-----------|------------|------------|
| Weekly | 4 | $11.40 |
| Daily | 30 | $85.50 |

---

## Quick Cost Check

### Check Current Spend
```bash
# OpenRouter API
curl https://openrouter.ai/api/v1/credits \
  -H "Authorization: Bearer $OPENROUTER_API_KEY"
```

### Check Token Usage in Logs
```bash
cd backend && grep -E "(prompt_tokens|completion_tokens)" storage/logs/laravel.log | tail -20
```

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `backend/app/Services/OpenRouterService.php` | API calls with token tracking |
| `backend/config/services.php` | Model configuration |
| `Bot.primary_chat_model` | Per-bot model selection |
| `Bot.decision_model` | Per-bot decision model |
