---
id: {prefix}-{number}-{slug}
title: {Title}
impact: HIGH | MEDIUM | LOW
impactDescription: "{One-line impact description}"
category: laravel | react | db | smell | pattern
tags: [{tag1}, {tag2}, {tag3}]
relatedRules: [{related-rule-id}]
---

## Code Smell

- Observable symptom 1
- Observable symptom 2
- What indicates this refactor is needed

## Root Cause

1. Why this code smell exists
2. How it develops over time
3. Impact on codebase

## When to Apply

- Specific conditions when this refactor makes sense
- When NOT to apply
- Prerequisites

## Solution

### Before

```php
// Code with the smell
```

### After

```php
// Refactored code
```

### Step-by-Step

1. **Step one**
   - What to do
   - Verification

2. **Step two**
   - What to do
   - Verification

## Verification

```bash
# Verify refactor is correct
php artisan test
npm run type-check
```

## Anti-Patterns

- What NOT to do
- Common mistakes
- Over-engineering risks

## Project-Specific Notes

**BotFacebook Context:**
- Specific files to watch
- Common patterns in project
- Related services/components
