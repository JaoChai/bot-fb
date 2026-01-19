---
id: ux-006-disabled-states
title: Clear Disabled State Visual
impact: MEDIUM
impactDescription: "Unclear disabled states confuse users about what they can click"
category: ux
tags: [disabled, states, feedback, buttons]
relatedRules: [a11y-001-color-contrast, style-002-cursor-pointer]
platforms: [All]
---

## Why This Matters

Users need to immediately recognize which elements are interactive and which are not. Unclear disabled states lead to repeated frustrated clicks.

## The Problem

```html
<!-- Bad: Same visual as enabled -->
<button disabled class="bg-blue-500 text-white">
  Submit
</button>

<!-- Bad: Only color change (accessibility issue) -->
<button disabled class="bg-gray-500 text-white">
  Submit
</button>
```

## Solution

```html
<!-- Good: Multiple visual cues -->
<button
  disabled
  class="bg-blue-500 text-white opacity-50 cursor-not-allowed"
>
  Submit
</button>

<!-- Good: With aria and explanation -->
<button
  disabled
  aria-disabled="true"
  title="Complete the form to submit"
  class="opacity-50 cursor-not-allowed pointer-events-none"
>
  Submit
</button>
```

### React Component Pattern

```tsx
interface ButtonProps {
  disabled?: boolean;
  loading?: boolean;
  children: React.ReactNode;
}

function Button({ disabled, loading, children }: ButtonProps) {
  const isDisabled = disabled || loading;

  return (
    <button
      disabled={isDisabled}
      aria-disabled={isDisabled}
      className={cn(
        "px-4 py-2 rounded bg-primary text-primary-foreground",
        isDisabled && "opacity-50 cursor-not-allowed"
      )}
    >
      {loading ? (
        <>
          <Spinner className="mr-2 animate-spin" />
          Loading...
        </>
      ) : (
        children
      )}
    </button>
  );
}
```

## Quick Reference

| Visual Cue | Purpose | Class |
|------------|---------|-------|
| Reduced opacity | Less prominent | `opacity-50` |
| Not-allowed cursor | Indicates disabled | `cursor-not-allowed` |
| Grayed out | Less saturated | `grayscale` |
| No pointer events | Prevent clicks | `pointer-events-none` |

## Tailwind Classes

```
opacity-50          - Half opacity (standard)
cursor-not-allowed  - Show "no" cursor
pointer-events-none - Disable all interaction
grayscale           - Remove color saturation
disabled:*          - Style when disabled
aria-disabled:*     - Style when aria-disabled
```

## Button States Reference

```html
<!-- All button states -->
<button class="
  bg-primary text-primary-foreground
  hover:bg-primary/90
  focus:ring-2 focus:ring-ring
  active:scale-95
  disabled:opacity-50 disabled:cursor-not-allowed
">
  Button
</button>
```

## Testing

- [ ] Disabled buttons look obviously different
- [ ] Cursor changes to not-allowed on hover
- [ ] Cannot click or focus disabled elements
- [ ] Screen readers announce disabled state

## Project-Specific Notes

**BotFacebook Context:**
- shadcn/ui Button has built-in disabled styles
- Use `disabled:opacity-50 disabled:pointer-events-none`
- Always disable submit buttons during `isPending`
- Form inputs should also show disabled state
