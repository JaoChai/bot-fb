---
id: gotcha-006-config-hierarchy
title: Config Hierarchy - Flow > Bot > Config > Hardcode
impact: CRITICAL
impactDescription: "Prevents LLM parameter mismatches and ensures Flow-level overrides work correctly"
category: gotcha
tags: [config, flow, bot, hierarchy, LLM, temperature, max_tokens]
relatedRules: [gotcha-001-config-null-coalesce, gotcha-004-env-vs-config]
---

## Why This Matters

BotFacebook has a multi-level configuration hierarchy for LLM parameters. When a Flow has specific settings (temperature, max_tokens), those MUST override Bot-level defaults. Getting this wrong causes the AI to behave differently than configured in the Flow editor, leading to hallucinations, inconsistent responses, or wasted tokens.

The hierarchy is: **Flow settings > Bot settings > config files > hardcoded defaults**

## Bad Example

```php
// Problem: Always using Bot-level settings, ignoring Flow overrides
class RAGService
{
    private function buildChatParams(Bot $bot, Flow $flow): array
    {
        return [
            'model' => $bot->primary_chat_model,
            'temperature' => $bot->temperature,      // Ignores Flow!
            'max_tokens' => $bot->max_tokens,         // Ignores Flow!
        ];
    }
}
```

**Why it's wrong:**
- Flow editor lets users set temperature/max_tokens per flow
- Bot-level settings are used as defaults only
- Users expect Flow settings to take effect immediately
- Different Flows may need different temperature (sales=0.3, support=0.7)

## Good Example

```php
// Solution: Flow settings override Bot settings with null coalescing
class RAGService
{
    private function buildChatParams(Bot $bot, Flow $flow): array
    {
        return [
            'model' => $bot->primary_chat_model,         // Model: Bot level only
            'temperature' => $flow->temperature ?? $bot->temperature ?? 0.7,
            'max_tokens' => $flow->max_tokens ?? $bot->max_tokens ?? 4096,
        ];
    }
}
```

**Why it's better:**
- Flow-level settings take priority when set
- Falls back to Bot-level when Flow doesn't specify
- Falls back to hardcoded default as last resort
- Each Flow can have independent LLM parameters

## Configuration Matrix

| Parameter | Flow Level | Bot Level | Config Level | Notes |
|-----------|-----------|-----------|-------------|-------|
| `model` | N/A (removed) | `primary_chat_model` | - | Model is Bot-level only |
| `temperature` | `flow.temperature` | `bot.temperature` | - | Flow overrides Bot |
| `max_tokens` | `flow.max_tokens` | `bot.max_tokens` | - | Flow overrides Bot |
| `system_prompt` | `flow.system_prompt` | - | - | Flow-level only |
| KB enabled | `flow_knowledge_base` junction | - | - | Flow attachment = enabled |
| Agent mode | `flow.is_agentic` | - | - | Flow-level only |

## Project-Specific Notes

**Key Services that must follow this hierarchy:**

```
app/Services/RAGService.php          → getChatModelForBot(), buildMessages()
app/Services/AgentLoopService.php    → getChatModel()
app/Http/Controllers/Api/StreamController.php → getChatModel()
app/Http/Controllers/Api/FlowController.php   → test()
```

**Recent change (Feb 2026):** Flow-level `model` fields were removed as dead code. Model selection now happens exclusively at Bot level (`primary_chat_model`). Only `temperature` and `max_tokens` use the Flow > Bot hierarchy.

**KB Detection:** Knowledge base is controlled entirely by Flow-level attachment via `flow_knowledge_base` junction table. The legacy `bot.kb_enabled` flag is no longer checked.

## References

- Commit `fa4a288`: fix: use Flow temperature/max_tokens instead of Bot
- Commit `e6cee6e`: fix: use Flow KB attachment instead of bot.kb_enabled
- Related rule: gotcha-001-config-null-coalesce
- Related rule: gotcha-004-env-vs-config
