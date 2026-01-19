---
id: {category}-{number}-{slug}
title: {Descriptive Title}
impact: {CRITICAL|HIGH|MEDIUM|LOW}
impactDescription: "{Why this matters}"
category: {category}
tags: [{tag1}, {tag2}, {tag3}]
relatedRules: [{other-rule-id}]
---

## Why This Matters

{1-2 sentences explaining the real-world impact of this issue}

## Bad Example

```{language}
// Anti-pattern code
```

**Why it's wrong:**
- {Specific problem 1}
- {Specific problem 2}

## Good Example

```{language}
// Correct pattern
```

**Why it's better:**
- {Benefit 1}
- {Benefit 2}

## Review Checklist

- [ ] {Check item 1}
- [ ] {Check item 2}
- [ ] {Check item 3}

## Detection

```bash
# Command to detect this issue
grep -r "pattern" --include="*.php"
```

## Project-Specific Notes

**BotFacebook Example:**

```{language}
// How this applies to our codebase
```

---

## Template Usage Notes

### Impact Levels

| Level | Use When |
|-------|----------|
| CRITICAL | Security vulnerabilities, data exposure, production crashes |
| HIGH | Bugs, N+1 queries, broken functionality |
| MEDIUM | Maintainability, code smell, conventions |
| LOW | Style, minor improvements, nice-to-have |

### Category Prefixes

| Prefix | Category | Examples |
|--------|----------|----------|
| backend- | Laravel Review | thin-controller, service-layer, formrequest |
| frontend- | React Review | hook-deps, memoization, state-management |
| security- | Security Check | sql-injection, xss, auth-bypass |
| api- | API Design | restful-naming, validation, response-format |
| perf- | Performance | n-plus-one, eager-loading, query-optimization |

### Review Checklist Guidelines

Checklist items should be:
- Actionable (can verify yes/no)
- Specific to this rule
- Ordered by importance
- Testable in code review

### Detection Commands

Include grep/ack commands to find violations:
```bash
# PHP patterns
grep -rn "pattern" --include="*.php" app/

# TypeScript patterns
grep -rn "pattern" --include="*.tsx" src/

# SQL patterns
grep -rn "DB::raw" --include="*.php" app/
```
