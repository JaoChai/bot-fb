# Rule Template

Universal template for all skill rules.

## File Naming

`{category}-{number}-{kebab-case-name}.md`

Examples:
- `gotcha-001-config-null-coalesce.md`
- `laravel-003-service-layer.md`
- `security-001-sql-injection.md`

## Impact Levels

| Level | When to Use | Examples |
|-------|-------------|----------|
| `CRITICAL` | Runtime failures, data loss, security issues, production outages | SQL injection, data corruption, auth bypass |
| `HIGH` | UX degradation, performance issues, maintainability problems | N+1 queries, missing validation, poor error handling |
| `MEDIUM` | Code quality, best practices, conventions | Code organization, naming, documentation |
| `LOW` | Nice-to-have, minor improvements | Stylistic preferences, optional optimizations |

## Template

```markdown
---
id: {category}-{number}-{name}
title: {Human Readable Title}
impact: CRITICAL | HIGH | MEDIUM | LOW
impactDescription: "{Measurable improvement or risk avoided}"
category: {category-prefix}
tags: [keyword1, keyword2]
relatedRules: [other-rule-id]
---

## Why This Matters

[1-2 paragraphs explaining the importance of this rule]

## Bad Example

\`\`\`{language}
// Problem: [description of the issue]
[anti-pattern code]
\`\`\`

**Why it's wrong:**
- [bullet point 1]
- [bullet point 2]

## Good Example

\`\`\`{language}
// Solution: [description of the fix]
[correct code]
\`\`\`

**Why it's better:**
- [bullet point 1]
- [bullet point 2]

## Project-Specific Notes

[BotFacebook-specific paths, patterns, or context]

## References

- [Link to documentation]
- [Related rule: rule-id]
```

## Optional Sections

For specific skill types, add these sections as needed:

### For Security Rules
```markdown
## Audit Command

\`\`\`bash
# Command to check for this vulnerability
grep -rn "pattern" app/ --include="*.php"
\`\`\`

## Attack Vector

[Description of how this vulnerability can be exploited]
```

### For Debug/Troubleshooting Rules
```markdown
## Debug Steps

1. Check X
2. Verify Y
3. If still failing, check Z

## Common Causes

| Symptom | Likely Cause | Fix |
|---------|--------------|-----|
| Error A | Cause 1 | Solution 1 |
```

### For Operations Rules
```markdown
## Checklist

- [ ] Pre-condition 1
- [ ] Pre-condition 2
- [ ] Execute step
- [ ] Post-verification

## Rollback Procedure

[Steps to revert if something goes wrong]
```

### For Review Rules
```markdown
## Review Checklist

- [ ] Check item 1
- [ ] Check item 2
- [ ] Verify item 3
```
