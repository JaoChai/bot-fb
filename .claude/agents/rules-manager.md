# Rules Manager Agent

## Description
Auto-manage rules in claude-mem. Load, resolve conflicts, prune unused, evolve new rules.

## Triggers
- SessionStart: LOAD rules
- Every 10 sessions: PRUNE unused rules
- Pattern detected 3+ times: EVOLVE new rule

## Model
haiku (token-efficient)

## Tools
- mem-search
- mem timeline
- mem get_observations

---

## Functions

### 1. LOAD (Every Session Start)

```
Input: project name, technology stack

Process:
1. mem-search "[RULE:*][UNIVERSAL]" → universal rules
2. mem-search "[RULE:*][TECHNOLOGY:Laravel]" → Laravel rules
3. mem-search "[RULE:*][TECHNOLOGY:React]" → React rules
4. mem-search "[RULE:*][PROJECT:BotFacebook]" → project rules
5. Deduplicate by rule type
6. Sort by priority (PROJECT > TECHNOLOGY > UNIVERSAL)

Output: JSON object with cached rules
{
  "budget": [...],
  "agent": [...],
  "workflow": [...],
  "optimization": [...]
}

Token Budget: ~500-1k
```

### 2. RESOLVE (On Conflict)

```
Priority Order:
1. [PROJECT:xxx]     → highest (most specific)
2. [TECHNOLOGY:xxx]  → medium
3. [UNIVERSAL]       → lowest (most general)

When same priority:
→ Use newer rule (by timestamp)

Record decision in narrative for future reference
```

### 3. PRUNE (Every 10 Sessions)

```
Process:
1. mem timeline → check rule usage history
2. Find rules not used in 30+ days
3. Find duplicate rules (same content, different IDs)
4. Find always-overridden rules

Actions:
- Unused 30+ days → suggest mark [INACTIVE]
- Duplicates → suggest merge
- Always overridden → suggest mark [DEPRECATED]

Report to user (no auto-delete)
```

### 4. EVOLVE (On Pattern Detection)

```
Trigger: Same pattern observed 3+ times

Process:
1. Detect repeated pattern
   "Every time debug LINE → use line-expert"

2. Generalize to rule
   [RULE:AGENT][TECHNOLOGY:LINE]
   "debug LINE issues → invoke line-expert skill"

3. Record with proper tags

Available immediately in next session
```

---

## Output Format

When invoked, return:

```json
{
  "loaded_rules": {
    "budget": [
      {"id": 123, "rule": "small task (<3 files) = no agent, target <1k tokens", "scope": "UNIVERSAL"}
    ],
    "agent": [
      {"id": 124, "rule": "complex search = Explore agent", "scope": "UNIVERSAL"}
    ],
    "workflow": [
      {"id": 125, "rule": "debug 500 = check logs first", "scope": "TECHNOLOGY:Laravel"}
    ]
  },
  "conflicts_resolved": 0,
  "tokens_used": 650
}
```

---

## Integration

This agent is invoked automatically by SessionStart hook.
Results are cached for the entire session.
No user interaction required.
