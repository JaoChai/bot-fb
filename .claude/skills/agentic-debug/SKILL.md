# Agentic Mode & Second AI Debugger

Agent loop, tool execution, smart routing, Second AI timeout/streaming issues.

## Architecture

```
StreamController
  ├─ Agentic Mode? (flow.agentic_mode)
  │   ├─ KB-First Pre-search
  │   ├─ Smart Routing Decision (complexity + toolIntent + kbQuality)
  │   └─ AgentLoopService.run() → ReAct Loop
  │       ├─ OpenRouter API (with tools)
  │       ├─ ToolService.executeTool()
  │       ├─ AgentSafetyService.checkLimits()
  │       └─ CostTrackingService.addCost()
  └─ Second AI Verification (after response)
      └─ SecondAIService.process() → fact/policy/personality checks
```

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/Agent/AgentLoopService.php` | ReAct loop orchestration |
| `app/Services/Agent/AgentLoopConfig.php` | Immutable input DTO |
| `app/Services/Agent/AgentLoopResult.php` | Output with usage metrics |
| `app/Services/Agent/SseAgentCallbacks.php` | SSE streaming events |
| `app/Services/Agent/SyncAgentCallbacks.php` | Webhook (silent, auto-reject HITL) |
| `app/Services/ToolService.php` | Tool definitions + execution |
| `app/Services/AgentSafetyService.php` | Timeout, cost, HITL |
| `app/Services/CostTrackingService.php` | Token usage tracking |
| `app/Services/SecondAIService.php` | Content verification pipeline |
| `config/tools.php` | 5 tool definitions (OpenAI spec) |
| `config/agent-prompts.php` | Thai/English system prompts |

## Debug Workflow

1. **Identify path**: Is it agentic mode or standard mode?
   - Check `flow.agentic_mode` and `flow.enabled_tools`
2. **Check smart routing**: Why was agent loop chosen/skipped?
   - Greeting detected → always skip
   - `complexity.is_complex OR toolIntent.needs_tool OR !hasHighQualityKb`
3. **Trace tool chain**: Which tools ran, in what order, with what results?
4. **Check safety**: Did timeout/cost/HITL trigger?
5. **Check Second AI**: Did it modify the response?

## Common Issues

### Agent Timeout
- Default: `flow.agent_timeout_seconds` = 120s
- Check happens BEFORE LLM call, not during
- Set timeout >= 2x expected LLM latency
- Webhook timeout < agent timeout (gateway may cut first)

### Second AI Timeout
- Pipeline timeout: 25s (separate from agent)
- Unified mode if 2+ checks (1 LLM call vs 3)
- Skip patterns: responses <50 chars without numbers, or greetings

### Max Iterations Hit

When `$iteration >= $maxIterations` and status is still `completed` (no safety violation):

1. Fires `onMaxIterations` callback
2. Makes one final LLM call with `tools: []` and `toolChoice: 'none'` — forces text response
3. This fallback call uses the same model, temperature, and maxTokens as normal iterations
4. Cost is tracked: `addCost()` is called for the fallback response tokens
5. Final status becomes `max_iterations` (not `error` or `completed`)
6. Content is emitted via `onContent` with source `max_iterations_fallback`

If the fallback LLM call itself fails:
- Status becomes `error`
- Error message from the language-aware config: `config("agent-prompts.{$language}.error_message")`
- Fix: increase `flow.max_tool_calls` or simplify prompts to reduce tool loops

### Cost Explosion
- Cost tracked AFTER LLM response (not predictive)
- Set both per-request AND daily limits
- Monitor: AgentCostUsage table

### HITL Blocking
- Webhook path: auto-rejects (no real-time connection)
- SSE path: polls cache with 0.5s heartbeat
- `null` hitl_dangerous_actions = use defaults
- `[]` empty array = explicitly allow all (semantic difference!)

### Tool Execution Errors
- search_kb: cache hit check (exact + >80% similarity), missing API key
- calculate: SafeMathCalculator (no eval), 500 char max
- think: no side effects, just logs
- get_current_datetime: timezone fallback to Asia/Bangkok
- escalate_to_human: logs only, returns confirmation

### Message Truncation

Handled by `AgentLoopService::truncateMessagesIfNeeded()` (called every iteration with `maxMessages=30`):

**Tier 1 — Compress old tool results (>20 messages):**
- `compressOldToolResults()` finds all `role=tool` messages
- Keeps the last 2 tool results intact
- Truncates older tool results to 100 chars + ` [compressed]` suffix

**Tier 2 — Drop oldest messages (>maxMessages, default 30):**
- Keeps system message (first) + last `maxMessages - 1` non-system messages
- Uses `array_slice($otherMessages, -$keepCount)` to keep newest

**Tier 3 — Remove orphaned tool results:**
- After slicing, if the first remaining message is `role=tool` (orphan without matching assistant tool_call), it gets removed via `array_shift`
- Repeats until first message is not a tool result

**Truncation note:**
- When messages are dropped, a user message is injected after system prompt
- Template from `config("agent-prompts.{$language}.truncation_note")`
- Thai: `[ระบบ: ข้อความก่อนหน้า %d ข้อความถูกตัดเพื่อประหยัด token]`
- English: `[System: %d previous messages were truncated to save tokens]`

## Smart Routing Decision Tree

```
Is Greeting? → YES → Skip agent (reason: greeting_detected)
              → NO  ↓
Has Tool Intent? (calculate/think keywords) → YES → Use agent (reason: tool_intent:{hint})
                                             → NO  ↓
Is Complex Question? → YES → Use agent (reason: complex_question)
                     → NO  ↓
Has High Quality KB? (similarity >= threshold) → YES → Skip agent (reason: simple_with_quality_kb)
                                                → NO  ↓
KB results exist but low quality? → YES → Use agent (reason: low_quality_kb)
                                   → NO  → Use agent (reason: no_kb_results)
```

### Routing Decision Codes

Returned in `shouldUseAgentLoop()` result as `reason`:

| Code | `use_agent` | Meaning |
|------|-------------|---------|
| `greeting_detected` | `false` | Greeting circuit breaker fired — always bypass agent |
| `simple_with_quality_kb` | `false` | Simple question + KB top relevance >= threshold |
| `tool_intent:{hint}` | `true` | User message matches tool keywords (hint = `calculate`, `get_current_datetime`, `escalate_to_human`, `think`) |
| `complex_question` | `true` | Complexity heuristic score >= threshold (default 2) |
| `low_quality_kb` | `true` | KB results exist but top relevance < threshold |
| `no_kb_results` | `true` | No KB results at all (relevance = 0) |

Source: `AgentLoopService::shouldUseAgentLoop()` (lines 404-442)

### Greeting Circuit Breaker

Greetings ALWAYS bypass the agent loop, regardless of other signals. This is the first check in `shouldUseAgentLoop()`:

```php
// In AgentLoopService::shouldUseAgentLoop()
if (! $complexity['is_complex'] && in_array('greeting_detected', $complexity['reasons'] ?? [])) {
    return ['use_agent' => false, 'reason' => 'greeting_detected', ...];
}
```

Greeting detection happens in `RAGService::detectComplexity()` using regex patterns:
- Thai: สวัสดี, หวัดดี, ดีครับ, ดีค่ะ, ดีจ้า, ดี
- English: hello, hi, hey, yo, good morning/afternoon/evening
- Must be standalone (anchored with `^...$` regex)

### Decision Model vs Routing Logic

These are **two separate concepts** — do not confuse them:

| Concept | What it does | Where it lives |
|---------|-------------|----------------|
| `bot.decision_model` | LLM model selection for intent analysis, Second AI checks, and agent chat calls | `Bot` model field, used by `IntentAnalysisService`, `AgentLoopService`, `SecondAIService` |
| `shouldUseAgentLoop()` | Routing logic — decides whether to enter the agent loop or use standard chat | `AgentLoopService::shouldUseAgentLoop()`, called from `StreamController` |

- `decision_model` picks WHICH LLM to call (e.g., `openai/gpt-4o-mini`)
- `shouldUseAgentLoop()` decides WHETHER to use the agent loop at all (based on complexity + tool intent + KB quality)
- In `AgentLoopService::run()`, the chat model is resolved as: `$bot->decision_model ?: getChatModel($bot)` — this is model selection, not routing
- Routing happens BEFORE the agent loop starts, in `StreamController` line ~287

## Flow Configuration

```php
'agentic_mode'              // boolean - enable agent
'enabled_tools'             // array - ['search_kb', 'calculate', 'think', ...]
'max_tool_calls'            // int - default 10
'agent_timeout_seconds'     // int - default 120
'agent_max_cost_per_request' // decimal - per-request limit
'hitl_enabled'              // boolean - human approval
'hitl_dangerous_actions'    // array|null - patterns (null=defaults, []=allow all)
'second_ai_enabled'         // boolean
'second_ai_options'         // {fact_check, policy, personality}
```

## Language-Aware Prompt Composition

`config/agent-prompts.php` defines prompt templates in two languages (`th` and `en`). `AgentLoopService::buildAgentSystemPrompt()` composes the final system prompt using the flow's language setting:

```php
$language = $flow->language ?? 'th';  // defaults to Thai
$prompts = config("agent-prompts.{$language}", config('agent-prompts.th'));  // fallback to Thai
```

**Composition order:**

1. **Memory prefix** — `buildMemoryPrefix($memoryNotes)` prepends `## Memory:` with bullet points
2. **Base prompt** — `$flow->system_prompt` or default bot prompt
3. **Pre-loaded KB context** — if `$kbContext` is non-empty, wraps with `pre_loaded_kb` / `pre_loaded_kb_suffix`
4. **Agent Decision Framework** — `agent_decision_framework` header + `decision_instant` (always included)
5. **Tool-specific decisions** — conditionally appended per enabled tool:
   - `search_kb` → `decision_search_with_kb` or `decision_search_no_kb` (depends on KB context)
   - `calculate` → `decision_calculate`
   - `think` → `decision_think`
   - `get_current_datetime` → `decision_datetime`
   - `escalate_to_human` → `decision_escalate`
6. **Search Strategy** — appended if `search_kb` is enabled
7. **Response Rules** — always appended
8. **Multiple Bubbles** — appended if bot has multiple bubbles enabled

**Available template keys per language:**

| Key | Purpose |
|-----|---------|
| `pre_loaded_kb` / `pre_loaded_kb_suffix` | Wraps KB context in the prompt |
| `agent_decision_framework` | Section header for decision rules |
| `decision_instant` | Rule: respond immediately for greetings/thanks/sufficient KB |
| `decision_search_with_kb` / `decision_search_no_kb` | Rule: when to use search_kb tool |
| `decision_calculate` | Rule: when to use calculate tool |
| `decision_think` | Rule: when to use think tool |
| `decision_datetime` | Rule: when to use datetime tool |
| `decision_escalate` | Rule: when to escalate to human |
| `search_strategy` | KB search best practices (max 2 searches, no repeat keywords) |
| `response_rules` | Response formatting rules (no guessing, be concise) |
| `truncation_note` | Injected when messages are truncated (uses `%d` placeholder) |
| `error_message` | Shown when agent loop encounters an unrecoverable error |

## Quick Commands

```bash
# Check agent loop test
php artisan test --filter=AgentLoopServiceTest

# Check tool definitions
php artisan tinker --execute="dd(config('tools'))"

# Check flow config
php artisan tinker --execute="dd(App\Models\Flow::find(ID)->only(['agentic_mode','enabled_tools','max_tool_calls','agent_timeout_seconds']))"

# Check cost usage
php artisan tinker --execute="dd(App\Models\AgentCostUsage::where('bot_id', ID)->latest()->first())"
```

## Second AI Pipeline

### Unified vs Sequential Mode

`SecondAIService::process()` orchestrates content verification with two modes:

- **Sequential** (1 check): Runs individual check service directly
- **Unified** (`shouldUseUnifiedMode()` — 2+ checks): Single LLM call via `UnifiedCheckService` instead of 3 separate calls

```
SecondAIService::process()
  → shouldUseUnifiedMode()? (2+ checks enabled)
    → YES: UnifiedCheckService (single LLM call, confidence filtering)
    → NO: Run individual check (FactCheck/Policy/Personality)
```

### Skip Patterns

Second AI skips verification when:
- Response < 50 chars AND contains no numbers (short greetings)
- Response matches greeting patterns

### Model Selection

```
bot.decision_model → fallback_decision_model → primary_chat_model
```

### Check Services

| Service | Purpose | Key File |
|---------|---------|----------|
| FactCheckService | Verify factual claims against KB | `app/Services/SecondAI/FactCheckService.php` |
| PolicyCheckService | Check policy compliance | `app/Services/SecondAI/PolicyCheckService.php` |
| PersonalityCheckService | Brand consistency | `app/Services/SecondAI/PersonalityCheckService.php` |
| UnifiedCheckService | Combined single-call mode | `app/Services/SecondAI/UnifiedCheckService.php` |
| PromptInjectionDetector | Detect injection attempts | `app/Services/SecondAI/PromptInjectionDetector.php` |

## Timeout Layering

| Layer | Default | Config Key |
|-------|---------|------------|
| Second AI Pipeline | 25s | `rag.second_ai.pipeline_timeout` |
| Second AI HTTP | 15s | `rag.second_ai.http_timeout` |
| Agent Loop | 120s | `flow.agent_timeout_seconds` |
| SSE Heartbeat | 30s | frontend threshold |

Timeouts are independent — pipeline timeout governs the entire Second AI process,
HTTP timeout governs individual LLM calls within it. Agent timeout is separate and
governs the agentic ReAct loop.

## StreamController Architecture

`StreamController` is the central orchestration point for the entire AI response pipeline.

### SSE Pipeline

```
Auth → Bot/Flow Resolution → Intent Analysis (Decision Model)
  → KB Search (if enabled) → Chat/Agent Response Generation
    → Second AI Verification → Multiple Bubbles Splitting
      → SSE Events to Frontend
```

### Key Behaviors

- **Heartbeat management**: Timer resets on each SSE event to keep connection alive
- **Memory notes injection**: Conversation memory_notes injected into system prompt for personalization
- **Octane-compatible resets**: All metrics/state reset at request start (no cross-request leaks)
- **Cost tracking**: Token usage recorded after each LLM call via CostTrackingService
- **Error recovery**: Catches exceptions at each stage, returns partial results with error context

### Key File

`app/Http/Controllers/Api/StreamController.php` (1,135 lines)

## MCP Tools

- `mcp__neon__run_sql` - Query agent_cost_usage, flow settings
- `mcp__sentry__search_issues` - Find agent timeout/error issues
- `mcp__railway__get-logs` - Check agent loop logs in production
