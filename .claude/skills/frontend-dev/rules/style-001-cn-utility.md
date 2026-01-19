---
id: style-001-cn-utility
title: cn() Utility for Class Merging
impact: MEDIUM
impactDescription: "Prevents Tailwind class conflicts and enables conditional styling"
category: style
tags: [tailwind, classnames, styling, cn]
relatedRules: [style-002-cva-variants, style-003-tailwind-conflicts]
---

## Why This Matters

Tailwind classes can conflict when combined from different sources (base styles, variants, props). The `cn()` utility merges classes intelligently, keeping only the last conflicting class.

It combines `clsx` for conditionals with `tailwind-merge` for conflict resolution.

## Bad Example

```tsx
// Problem 1: String concatenation - conflicts not resolved
function Button({ className, variant }) {
  const baseClasses = 'px-4 py-2 bg-blue-500';
  return (
    <button className={baseClasses + ' ' + className}>
      {/* If className="bg-red-500", both bg-blue-500 and bg-red-500 apply! */}
    </button>
  );
}

// Problem 2: Template literal with conflicts
function Card({ highlighted }) {
  return (
    <div className={`p-4 rounded ${highlighted ? 'p-6' : ''}`}>
      {/* Both p-4 and p-6 in class string! */}
    </div>
  );
}

// Problem 3: Array join without merge
function Input({ error, className }) {
  const classes = [
    'border rounded px-3 py-2',
    error && 'border-red-500',
    className,
  ].filter(Boolean).join(' ');

  return <input className={classes} />;
  // Still no conflict resolution
}

// Problem 4: Overriding default margin fails
<Card className="mt-8" />
// If Card has mt-4 internally, both apply
```

**Why it's wrong:**
- Conflicting utilities both apply, last in CSS wins (unpredictable)
- Template literals don't resolve conflicts
- Array join is just concatenation
- Can't override component defaults reliably

## Good Example

```tsx
// Solution: Use cn() from lib/utils
import { cn } from '@/lib/utils';

// Basic usage - merges and resolves conflicts
function Button({ className, children }) {
  return (
    <button className={cn(
      'px-4 py-2 bg-blue-500 text-white rounded',
      className // className="bg-red-500" → only bg-red-500 applied
    )}>
      {children}
    </button>
  );
}

// Conditional classes
function Card({ highlighted, disabled, className }) {
  return (
    <div className={cn(
      'p-4 rounded border bg-card',
      highlighted && 'p-6 border-primary', // p-6 overrides p-4
      disabled && 'opacity-50 cursor-not-allowed',
      className
    )}>
      {/* ... */}
    </div>
  );
}

// Object syntax for complex conditions
function Badge({ variant, size, className }) {
  return (
    <span className={cn(
      'inline-flex items-center rounded-full font-medium',
      {
        'bg-primary text-primary-foreground': variant === 'default',
        'bg-destructive text-destructive-foreground': variant === 'destructive',
        'bg-secondary text-secondary-foreground': variant === 'secondary',
      },
      {
        'px-2 py-0.5 text-xs': size === 'sm',
        'px-3 py-1 text-sm': size === 'md',
        'px-4 py-1.5 text-base': size === 'lg',
      },
      className
    )}>
      {/* ... */}
    </span>
  );
}

// Merging multiple sources
function Input({ error, disabled, size, className }) {
  return (
    <input
      className={cn(
        // Base styles
        'flex w-full rounded-md border bg-background px-3 py-2',
        'focus-visible:outline-none focus-visible:ring-2',

        // Size variants
        size === 'sm' && 'h-8 text-sm',
        size === 'lg' && 'h-12 text-lg',

        // State styles
        error && 'border-destructive focus-visible:ring-destructive',
        disabled && 'cursor-not-allowed opacity-50',

        // Consumer overrides (always last)
        className
      )}
      disabled={disabled}
    />
  );
}

// cn() implementation (in lib/utils.ts)
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
```

**Why it's better:**
- `tailwind-merge` resolves conflicts (last wins)
- `clsx` handles conditionals and objects
- Consumer `className` can override defaults
- Predictable, maintainable styling

## Project-Specific Notes

**cn() Location:**
```tsx
// frontend/src/lib/utils.ts
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}
```

**Import Pattern:**
```tsx
import { cn } from '@/lib/utils';
```

**Common Patterns:**

| Pattern | Example |
|---------|---------|
| Base + override | `cn('p-4', className)` |
| Conditional | `cn('base', isActive && 'active')` |
| Object syntax | `cn({ 'text-red': error })` |
| Multiple conditions | `cn('base', a && 'x', b && 'y')` |

**What Gets Merged:**

| Classes | Result | Reason |
|---------|--------|--------|
| `p-4 p-6` | `p-6` | Same property |
| `mt-4 mb-4` | `mt-4 mb-4` | Different properties |
| `text-red text-blue` | `text-blue` | Same property |
| `hover:bg-red bg-blue` | both | Different states |

## References

- [tailwind-merge](https://github.com/dcastil/tailwind-merge)
- [clsx](https://github.com/lukeed/clsx)
