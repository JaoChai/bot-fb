# Rule Template

Use this template when creating new rules for the frontend-dev skill.

## File Naming

`{category}-{number}-{kebab-case-name}.md`

Examples:
- `react-001-component-structure.md`
- `query-002-enabled-option.md`
- `gotcha-001-response-data-access.md`

## Categories

| Prefix | Category | Description |
|--------|----------|-------------|
| `react-` | React | Component patterns, hooks, React 19 features |
| `query-` | React Query | Data fetching, caching, mutations |
| `state-` | State | Zustand, local state, prop drilling |
| `perf-` | Performance | Memoization, code splitting, bundle size |
| `a11y-` | Accessibility | ARIA, keyboard nav, semantic HTML |
| `style-` | Styling | Tailwind, CVA, cn utility |
| `ts-` | TypeScript | Types, generics, inference |
| `gotcha-` | Gotchas | Common mistakes and pitfalls |

## Impact Levels

| Level | When to Use | Examples |
|-------|-------------|----------|
| `CRITICAL` | Runtime failures, data loss, security issues | API response access, infinite loops |
| `HIGH` | UX degradation, performance issues | Cache invalidation, error handling |
| `MEDIUM` | Code quality, maintainability | Code organization, naming |
| `LOW` | Nice-to-have, minor improvements | Stylistic preferences |

## Template

```markdown
---
id: {category}-{number}-{name}
title: {Human Readable Title}
impact: CRITICAL | HIGH | MEDIUM | LOW
impactDescription: "{Measurable improvement or risk avoided}"
category: react | query | state | perf | a11y | style | ts | gotcha
tags: [keyword1, keyword2]
relatedRules: [other-rule-id]
---

## Why This Matters

[1-2 paragraphs explaining the importance of this rule]

## Bad Example

\`\`\`tsx
// Problem: [description of the issue]
[anti-pattern code]
\`\`\`

**Why it's wrong:**
- [bullet point 1]
- [bullet point 2]

## Good Example

\`\`\`tsx
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
