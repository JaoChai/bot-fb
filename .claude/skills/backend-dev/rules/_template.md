# Rule Template

Use this template when creating new rules for the backend-dev skill.

## File Naming

`{category}-{number}-{kebab-case-name}.md`

Examples:
- `gotcha-001-config-null-coalesce.md`
- `laravel-003-service-layer.md`
- `security-001-input-validation.md`

## Categories

| Prefix | Category | Description |
|--------|----------|-------------|
| `gotcha-` | Gotchas | Common mistakes and pitfalls |
| `laravel-` | Laravel Core | Service providers, configuration, core patterns |
| `eloquent-` | Eloquent | Models, relationships, query scopes |
| `api-` | API Design | RESTful conventions, responses, status codes |
| `job-` | Queue Jobs | Job processing, failure handling |
| `event-` | Events | Events, listeners, broadcasting |
| `policy-` | Authorization | Policies, gates, permissions |
| `security-` | Security | Validation, injection prevention |

## Impact Levels

| Level | When to Use | Examples |
|-------|-------------|----------|
| `CRITICAL` | Runtime failures, data loss, security issues | SQL injection, auth bypass, data corruption |
| `HIGH` | UX degradation, performance issues | N+1 queries, missing validation |
| `MEDIUM` | Code quality, maintainability | Code organization, naming |
| `LOW` | Nice-to-have, minor improvements | Stylistic preferences |

## Template

```markdown
---
id: {category}-{number}-{name}
title: {Human Readable Title}
impact: CRITICAL | HIGH | MEDIUM | LOW
impactDescription: "{Measurable improvement or risk avoided}"
category: gotcha | laravel | eloquent | api | job | event | policy | security
tags: [keyword1, keyword2]
relatedRules: [other-rule-id]
---

## Why This Matters

[1-2 paragraphs explaining the importance of this rule]

## Bad Example

\`\`\`php
// Problem: [description of the issue]
[anti-pattern code]
\`\`\`

**Why it's wrong:**
- [bullet point 1]
- [bullet point 2]

## Good Example

\`\`\`php
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
