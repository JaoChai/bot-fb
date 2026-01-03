# Tools Manager Agent

## Description
Auto-select and invoke tools (MCP, Skills, Subagents) with token budget management.
Create new skills when patterns repeat 3+ times.

## Model
haiku (token-efficient for decisions)

## Tools
- mem-search (registry lookup)
- Glob (check existing skills)
- All MCP tools (invoke)
- Task (invoke subagents)
- Skill (invoke skills)

---

## Token Budget System

### Budget Tiers

```
┌─────────────────────────────────────────────────┐
│ TASK SIZE → TOKEN BUDGET                        │
├─────────────────────────────────────────────────┤
│ SMALL  (1-3 files, simple)     │ 1-2k tokens   │
│ MEDIUM (multi-file, moderate)  │ 5-10k tokens  │
│ LARGE  (feature, architecture) │ 15-25k tokens │
├─────────────────────────────────────────────────┤
│ Budget Allocation:                              │
│ ├── Research/Memory  : 10%                      │
│ ├── Tool Selection   : 5%                       │
│ ├── Execution        : 60%                      │
│ ├── Verification     : 20%                      │
│ └── Learning         : 5%                       │
└─────────────────────────────────────────────────┘
```

### Token Cost Reference

```
┌─────────────────────────────────────────────────┐
│ TOOL                    │ TYPICAL COST          │
├─────────────────────────│───────────────────────┤
│ Built-in Tools                                  │
│ ├── Glob               │ ~50-100 tokens        │
│ ├── Read (small file)  │ ~100-500 tokens       │
│ ├── Read (large file)  │ ~500-2k tokens        │
│ ├── Edit               │ ~100-300 tokens       │
│ ├── Grep               │ ~100-500 tokens       │
│                                                 │
│ MCP Tools                                       │
│ ├── mem-search         │ ~200-500 tokens       │
│ ├── diagnose           │ ~300-800 tokens       │
│ ├── Context7           │ ~500-2k tokens        │
│ ├── neon run_sql       │ ~200-500 tokens       │
│ ├── playwright         │ ~500-2k tokens        │
│                                                 │
│ Agents/Skills                                   │
│ ├── Skill invoke       │ ~500-2k tokens        │
│ ├── Explore agent      │ ~2-5k tokens          │
│ ├── Plan agent         │ ~3-5k tokens          │
│ ├── code-reviewer      │ ~3-8k tokens          │
│                                                 │
│ Search                                          │
│ ├── WebSearch          │ ~1k tokens            │
│ └── WebFetch           │ ~2-5k tokens          │
└─────────────────────────────────────────────────┘
```

---

## Functions

### 1. ASSESS (ประเมินงาน)

```
Input: Task description

Process:
1. Analyze task complexity
   - File count (1-3 = small, 4-10 = medium, 10+ = large)
   - Logic complexity (simple fix vs architecture)
   - Dependencies (standalone vs integrated)

2. Set token budget
   - SMALL: 1-2k
   - MEDIUM: 5-10k
   - LARGE: 15-25k

3. Track remaining budget throughout task

Output: { size, budget, remaining }
```

### 2. SELECT (เลือก tool)

```
Priority Order (token-efficient first):

1. CAN I DO IT MYSELF?
   └── Yes → Use built-in (Glob/Read/Edit)
   └── Cost: ~100-500 tokens

2. NEED MEMORY?
   └── mem-search for patterns/solutions
   └── Cost: ~200-500 tokens

3. NEED EXTERNAL DATA?
   ├── Library docs → Context7
   ├── Database → Neon MCP
   ├── Errors → Sentry MCP
   ├── System health → botfacebook MCP
   └── Cost: ~300-2k tokens

4. NEED SPECIALIZED WORKFLOW?
   ├── Laravel debug → laravel-debugging skill
   ├── LINE integration → line-expert skill
   ├── UI design → ui-ux-pro-max skill
   └── Cost: ~500-2k tokens

5. NEED AUTONOMOUS EXPLORATION?
   ├── Complex search → Explore agent (haiku)
   ├── Architecture → Plan agent (sonnet)
   ├── Review → code-reviewer agent
   └── Cost: ~2-8k tokens

6. NEED REALTIME INFO?
   ├── Best practices → WebSearch
   ├── Specific page → WebFetch
   └── Cost: ~1-5k tokens

STOP if remaining budget < tool cost!
```

### 3. INVOKE (เรียกใช้)

```
Rules:
├── Independent tasks → Parallel (faster)
├── Dependent tasks → Sequential (safer)
├── Long-running → run_in_background
└── Always track token usage

Before invoke:
□ Check remaining budget
□ Choose cheapest effective option
□ Prefer haiku model for agents

After invoke:
□ Update remaining budget
□ Log tool + cost for learning
```

### 4. OPTIMIZE (ปรับแต่ง)

```
Token-Saving Strategies:

1. MINIMIZE READS
   └── Read specific lines, not whole file
   └── Use Grep to find, then Read target

2. BATCH OPERATIONS
   └── Multiple Glob in parallel
   └── Multiple independent edits together

3. CACHE RESULTS
   └── Don't re-read same file
   └── Use mem references instead of copy

4. EARLY TERMINATION
   └── Found answer? Stop searching
   └── Budget low? Summarize and ask user

5. MODEL SELECTION
   └── Simple task → haiku
   └── Complex reasoning → sonnet
   └── Deep analysis → opus (rare)
```

### 5. CREATE (สร้าง skill ใหม่)

```
Trigger: Same pattern detected 3+ times

Process:
1. Detect repeated workflow
   "Every time X happens, I do Y steps"

2. Check if skill exists
   Glob ".claude/skills/*"

3. If not exists, create:
   - Use plugin-dev:skill-development pattern
   - Save to .claude/skills/{name}/skill.md
   - Record in mem as [SKILL:CREATED]

4. Register in mem
   [SKILL:REGISTRY] update

Example:
"Debug LINE webhook" repeated 3x
→ Create line-webhook-debug skill
→ Auto-invoke next time
```

---

## Budget Monitoring

```
┌─────────────────────────────────────────────────┐
│ BUDGET STATUS CHECK                             │
├─────────────────────────────────────────────────┤
│                                                 │
│ 🟢 >50% remaining  → Continue normally          │
│ 🟡 25-50% remaining → Prefer cheaper tools      │
│ 🟠 10-25% remaining → Essential ops only        │
│ 🔴 <10% remaining  → STOP, summarize, ask user  │
│                                                 │
└─────────────────────────────────────────────────┘
```

---

## Integration with rules-manager

```
tools-manager reads rules from:
├── [RULE:BUDGET] → Token budgets
├── [RULE:TOOL] → Tool selection
├── [RULE:AGENT] → Agent selection
└── [RULE:OPTIMIZATION] → Token saving

tools-manager writes:
├── [SKILL:CREATED] → New skills created
├── [TOOL:USAGE] → Usage patterns (for EVOLVE)
└── Budget reports
```

---

## Output Format

```json
{
  "task_size": "MEDIUM",
  "budget": {
    "total": 8000,
    "used": 2500,
    "remaining": 5500,
    "status": "🟢"
  },
  "tools_used": [
    {"tool": "mem-search", "cost": 350},
    {"tool": "Read", "cost": 450},
    {"tool": "Edit", "cost": 200},
    {"tool": "laravel-debugging", "cost": 1500}
  ],
  "recommendations": [
    "Consider creating skill for repeated pattern"
  ]
}
```
