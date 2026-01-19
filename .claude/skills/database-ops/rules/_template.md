# Database Operations Rule Template

Use this template when creating new rules for database-ops skill.

---

## Required Frontmatter

```yaml
---
id: {category}-{number}-{short-name}
title: {Title Case Name}
impact: CRITICAL | HIGH | MEDIUM | LOW
impactDescription: "{One line explaining the impact}"
category: {migration|safety|vector|index|perf|gotcha|neon}
tags: [{tag1}, {tag2}, {tag3}]
relatedRules: [{related-rule-id}]
---
```

## Impact Levels

| Level | Use When |
|-------|----------|
| **CRITICAL** | Data loss, production failures, corruption |
| **HIGH** | Performance degradation, operational issues |
| **MEDIUM** | Code quality, maintainability |
| **LOW** | Nice-to-have improvements |

## Standard Sections

### 1. Why This Matters
2-3 sentences explaining the importance.

### 2. Bad Example
```sql
-- Problem: [description]
[bad SQL/PHP code]
```

**Why it's wrong:**
- Point 1
- Point 2

### 3. Good Example
```sql
-- Good: [description]
[good SQL/PHP code]
```

**Why it's better:**
- Point 1
- Point 2

### 4. Project-Specific Notes
BotFacebook-specific examples and conventions.

### 5. MCP Tools (Optional)
```
# Neon MCP tool usage
mcp__neon__run_sql(...)
```

### 6. Audit Command (Optional)
```bash
# How to check for violations
[command]
```

### 7. References
- [Link Name](URL)

---

## Category Prefixes

| Prefix | Category | Example |
|--------|----------|---------|
| migration- | Migrations | migration-001-nullable-columns |
| safety- | Dangerous Operations | safety-001-not-null-constraint |
| vector- | pgvector Operations | vector-001-create-extension |
| index- | Index Strategy | index-001-hnsw-vs-ivfflat |
| perf- | Performance | perf-001-explain-analyze |
| gotcha- | Common Gotchas | gotcha-001-pool-exhaustion |
| neon- | Neon-Specific | neon-001-branching |
