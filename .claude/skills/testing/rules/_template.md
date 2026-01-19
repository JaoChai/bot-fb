# Testing Rule Template

```markdown
---
id: {prefix}-{number}-{slug}
title: {Title}
impact: CRITICAL | HIGH | MEDIUM | LOW
impactDescription: "{What happens if not followed}"
category: {unit|feature|e2e|ui|a11y}
tags: [tag1, tag2, tag3]
relatedRules: [related-rule-id]
---

## Why This Matters

Brief explanation of why this testing pattern is important.

## Bad Example

```php
// or typescript for E2E
// Example of incorrect test pattern
```

**Why it's problematic:**
- Point 1
- Point 2

## Good Example

```php
// or typescript for E2E
// Example of correct test pattern
```

**Why it's better:**
- Point 1
- Point 2

## Test Coverage

| Scenario | Priority |
|----------|----------|
| Scenario 1 | Must test |
| Scenario 2 | Should test |
| Scenario 3 | Nice to have |

## Run Command

```bash
# Command to run this type of test
php artisan test --filter TestName
```

## Project-Specific Notes

**BotFacebook Testing:**

```php
// Project-specific test examples
```
```

## Field Reference

| Field | Required | Description |
|-------|----------|-------------|
| id | Yes | Format: {category}-{number}-{slug} |
| title | Yes | Descriptive title for the rule |
| impact | Yes | CRITICAL/HIGH/MEDIUM/LOW |
| impactDescription | Yes | Brief impact description |
| category | Yes | unit/feature/e2e/ui/a11y |
| tags | Yes | Array of relevant tags |
| relatedRules | No | Array of related rule IDs |

## Categories

| Prefix | Name | Focus |
|--------|------|-------|
| unit- | Unit Testing | Service/model isolation |
| feature- | Feature Testing | Controller/API integration |
| e2e- | E2E Testing | Full user flows |
| ui- | UI Testing | Visual/responsive |
| a11y- | Accessibility | WCAG compliance |
