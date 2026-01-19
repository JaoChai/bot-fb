---
id: react-003-extract-utility
title: Extract Utility Function Refactoring
impact: MEDIUM
impactDescription: "Extract pure functions to utility modules"
category: react
tags: [extract, utility, pure-function, dry]
relatedRules: [react-002-extract-hook, smell-002-duplicate-code]
---

## Code Smell

- Same function in multiple files
- Pure functions inside components
- Formatting/transformation logic repeated
- Helper functions growing in components

## Root Cause

1. Quick inline solutions
2. No utility pattern established
3. Copy-paste development
4. Unclear where to put helpers
5. Functions evolved locally

## When to Apply

**Apply when:**
- Same function in 2+ places
- Pure function (no side effects)
- Logic is generic/reusable
- Function is > 5 lines

**Don't apply when:**
- Truly component-specific
- One-liner that's readable
- Would add confusion

## Solution

### Before

```tsx
// BotCard.tsx
function BotCard({ bot }: { bot: Bot }) {
  const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  };

  const truncateText = (text: string, maxLength: number) => {
    if (text.length <= maxLength) return text;
    return text.slice(0, maxLength) + '...';
  };

  const formatNumber = (num: number) => {
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
  };

  return (
    <Card>
      <CardTitle>{truncateText(bot.name, 30)}</CardTitle>
      <CardDescription>{truncateText(bot.description, 100)}</CardDescription>
      <div>Created: {formatDate(bot.created_at)}</div>
      <div>Messages: {formatNumber(bot.message_count)}</div>
    </Card>
  );
}

// ConversationItem.tsx - SAME FUNCTIONS
function ConversationItem({ conversation }) {
  const formatDate = (date: string) => {
    // Same implementation...
  };

  const truncateText = (text: string, maxLength: number) => {
    // Same implementation...
  };

  // ...
}
```

### After

```tsx
// lib/format.ts
export function formatDate(
  date: string | Date,
  options: Intl.DateTimeFormatOptions = {}
): string {
  const defaultOptions: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    ...options,
  };

  return new Date(date).toLocaleDateString('en-US', defaultOptions);
}

export function formatRelativeDate(date: string | Date): string {
  const now = new Date();
  const target = new Date(date);
  const diff = now.getTime() - target.getTime();

  const minutes = Math.floor(diff / 60000);
  const hours = Math.floor(diff / 3600000);
  const days = Math.floor(diff / 86400000);

  if (minutes < 1) return 'just now';
  if (minutes < 60) return `${minutes}m ago`;
  if (hours < 24) return `${hours}h ago`;
  if (days < 7) return `${days}d ago`;
  return formatDate(date);
}

// lib/string.ts
export function truncate(text: string, maxLength: number): string {
  if (text.length <= maxLength) return text;
  return text.slice(0, maxLength).trim() + '...';
}

export function capitalize(text: string): string {
  return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase();
}

export function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^\w\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .trim();
}

// lib/number.ts
export function formatCompact(num: number): string {
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
  if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
  return num.toString();
}

export function formatCurrency(
  amount: number,
  currency = 'USD'
): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency,
  }).format(amount);
}

export function formatPercent(value: number, decimals = 0): string {
  return `${(value * 100).toFixed(decimals)}%`;
}

// BotCard.tsx - CLEAN
import { formatDate, formatCompact, truncate } from '@/lib/format';

function BotCard({ bot }: { bot: Bot }) {
  return (
    <Card>
      <CardTitle>{truncate(bot.name, 30)}</CardTitle>
      <CardDescription>{truncate(bot.description, 100)}</CardDescription>
      <div>Created: {formatDate(bot.created_at)}</div>
      <div>Messages: {formatCompact(bot.message_count)}</div>
    </Card>
  );
}
```

### Step-by-Step

1. **Identify duplicate functions**
   ```bash
   grep -rn "formatDate" src/
   grep -rn "truncate" src/
   ```

2. **Create utility module**
   ```bash
   touch src/lib/format.ts
   touch src/lib/string.ts
   ```

3. **Move and generalize**
   - Copy function
   - Add TypeScript types
   - Make generic if needed
   - Export

4. **Update imports**
   - Import from utility
   - Remove local definitions

5. **Add tests**
   ```bash
   touch src/lib/__tests__/format.test.ts
   ```

## Verification

```bash
# Type check
npm run type-check

# Test utilities
npm run test -- lib/format

# Verify no duplicates remain
grep -rn "const formatDate" src/components/
# Should return nothing
```

## Anti-Patterns

- **Utility soup**: One file with everything
- **Over-generalization**: Making simple things complex
- **Missing tests**: Utilities should be well tested
- **Breaking changes**: Be careful when modifying

## Project-Specific Notes

**BotFacebook Context:**
- Utilities location: `src/lib/`
- Categories: `format.ts`, `string.ts`, `number.ts`, `validators.ts`
- Use named exports
- Add JSDoc comments for complex utilities
