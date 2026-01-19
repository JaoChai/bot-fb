---
id: style-003-tailwind-conflicts
title: Tailwind Class Conflicts
impact: MEDIUM
impactDescription: "Prevents unexpected styling from conflicting utility classes"
category: style
tags: [tailwind, css, conflicts, debugging]
relatedRules: [style-001-cn-utility]
---

## Why This Matters

When multiple Tailwind classes target the same CSS property, CSS specificity rules apply - usually the last class in the stylesheet wins, NOT the last in your className string. This causes unexpected styling that's hard to debug.

Understanding how conflicts occur helps prevent styling bugs.

## Bad Example

```tsx
// Problem 1: Conflicting spacing
<div className="p-4 p-2">
  {/* Which padding applies? Depends on CSS generation order */}
</div>

// Problem 2: Conflicting colors
<button className="bg-blue-500 bg-red-500 hover:bg-green-500">
  {/* Base color unclear */}
</button>

// Problem 3: Responsive conflicts
<div className="md:flex md:block">
  {/* Both apply at md breakpoint - which wins? */}
</div>

// Problem 4: Arbitrary value conflicts
<div className="w-[100px] w-full">
  {/* Arbitrary and utility conflict */}
</div>

// Problem 5: Not understanding what cn() merges
<div className={cn('p-4 text-red-500', 'p-2 text-blue-500')}>
  {/* Result: p-2 text-blue-500 - both were overridden */}
</div>

// Problem 6: Dark mode conflicts
<div className="bg-white dark:bg-black bg-gray-100">
  {/* bg-gray-100 overrides bg-white, dark:bg-black might not work */}
</div>
```

**Why it's wrong:**
- CSS order determines winner, not className order
- Debugging requires checking compiled CSS
- Responsive variants can conflict
- Arbitrary values add complexity
- Dark mode variants have special rules

## Good Example

```tsx
// Solution 1: Use cn() to resolve conflicts intentionally
import { cn } from '@/lib/utils';

// Base + override pattern
<div className={cn('p-4', isCompact && 'p-2')}>
  {/* When isCompact: p-2 applied (cn removes p-4) */}
</div>

// Solution 2: Understand cn() merge behavior
function Card({ className }) {
  return (
    <div className={cn(
      'p-4 bg-white rounded',  // Defaults
      className                 // Overrides conflicting classes
    )}>
      {/* className="p-6 bg-gray-100" → p-6 bg-gray-100 rounded */}
    </div>
  );
}

// Solution 3: Avoid unnecessary conflicts
// Instead of:
<div className="md:flex md:block md:hidden" />

// Use correct utilities:
<div className="hidden md:flex" />  // Hidden by default, flex at md+
<div className="md:hidden" />       // Visible by default, hidden at md+

// Solution 4: Be explicit with variants
<button className={cn(
  // Light mode
  'bg-white text-black',
  // Dark mode (separate, no conflict)
  'dark:bg-gray-900 dark:text-white',
  // Hover states
  'hover:bg-gray-100 dark:hover:bg-gray-800'
)}>
  Click
</button>

// Solution 5: Use CSS variables for theming
// tailwind.config.ts
theme: {
  extend: {
    colors: {
      background: 'hsl(var(--background))',
      foreground: 'hsl(var(--foreground))',
    }
  }
}

// Component - no conflicts
<div className="bg-background text-foreground">
  {/* CSS variables handle dark mode automatically */}
</div>

// Solution 6: Debug with browser DevTools
// 1. Inspect element
// 2. Look at Styles panel
// 3. Check which rules are crossed out (overridden)
// 4. Note the source file and line

// Solution 7: Understand what tailwind-merge groups
// These are the SAME group (conflict resolved):
cn('p-4', 'p-2')         // → 'p-2'
cn('px-4', 'px-2')       // → 'px-2'
cn('text-sm', 'text-lg') // → 'text-lg'
cn('bg-red-500', 'bg-blue-500') // → 'bg-blue-500'

// These are DIFFERENT groups (both kept):
cn('p-4', 'px-2')        // → 'p-4 px-2' (p vs px)
cn('mr-4', 'ml-2')       // → 'mr-4 ml-2' (different sides)
cn('text-red-500', 'bg-red-500') // → 'text-red-500 bg-red-500'

// Solution 8: State variants don't conflict with base
cn('bg-white', 'hover:bg-gray-100') // → 'bg-white hover:bg-gray-100'
// Both kept - different states

// But same state DOES conflict
cn('hover:bg-white', 'hover:bg-gray-100') // → 'hover:bg-gray-100'
```

**Why it's better:**
- `cn()` makes conflict resolution explicit
- CSS variables avoid light/dark conflicts
- Understanding merge groups prevents bugs
- DevTools helps debug when needed

## Project-Specific Notes

**tailwind-merge Conflict Groups:**

| Group | Examples |
|-------|----------|
| Padding | p-*, px-*, py-*, pt-*, etc. |
| Margin | m-*, mx-*, my-*, mt-*, etc. |
| Width | w-*, min-w-*, max-w-* |
| Font size | text-xs, text-sm, text-base, etc. |
| Colors | text-*, bg-*, border-* |
| Display | block, flex, grid, hidden |

**Not Conflicting (same group, different axis):**
- `mx-4` vs `my-4` - different axes
- `pt-4` vs `pb-4` - different sides
- `text-red-500` vs `bg-red-500` - different properties

**Debugging Checklist:**
1. Is `cn()` being used?
2. Are classes in the same conflict group?
3. Check browser DevTools computed styles
4. Look for typos in class names

## References

- [tailwind-merge Documentation](https://github.com/dcastil/tailwind-merge)
- [Tailwind CSS Specificity](https://tailwindcss.com/docs/styling-with-utility-classes)
