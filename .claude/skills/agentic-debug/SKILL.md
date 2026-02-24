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
- Fallback: final LLM call with `toolChoice='none'`
- This call ALSO incurs cost
- Status: `max_iterations` (not `error`)
- Fix: increase `flow.max_tool_calls` or simplify prompts

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
- Tier 1 (>20 msgs): compress old tool results to 100 chars
- Tier 2 (>30 msgs): keep system + last 29
- Tier 3: drop orphaned tool results
- Truncation note injected as user message

## Smart Routing Decision Tree

```
Is Greeting? → YES → Skip agent (simple response)
              → NO  ↓
Has Tool Intent? (calculate/think keywords) → YES → Use agent
                                             → NO  ↓
Is Complex Question? → YES → Use agent
                     → NO  ↓
Has High Quality KB? (similarity > threshold) → YES → Skip agent (KB response)
                                               → NO  → Use agent
```

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

## MCP Tools

- `mcp__neon__run_sql` - Query agent_cost_usage, flow settings
- `mcp__sentry__search_issues` - Find agent timeout/error issues
- `mcp__railway__get-logs` - Check agent loop logs in production
