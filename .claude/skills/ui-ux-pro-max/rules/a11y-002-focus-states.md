---
id: a11y-002-focus-states
title: Visible Focus Indicators
impact: CRITICAL
impactDescription: "Keyboard users can't navigate without visible focus states"
category: a11y
tags: [focus, keyboard, navigation, wcag]
relatedRules: [a11y-004-keyboard-nav, style-002-cursor-pointer]
platforms: [Web]
---

## Why This Matters

Millions of users navigate with keyboard only (motor impairments, power users, screen readers). Without visible focus indicators, they can't see where they are on the page.

## The Problem

```css
/* Bad: Removing focus outline without replacement */
button:focus {
  outline: none; /* Keyboard users now blind! */
}

/* Bad: Tailwind shortcut that removes accessibility */
<button class="focus:outline-none">
  Inaccessible
</button>
```

## Solution

```html
<!-- Good: Tailwind focus ring -->
<button class="focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2">
  Accessible
</button>

<!-- Good: Custom visible focus -->
<button class="focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2">
  Shows ring only on keyboard focus
</button>
```

### Focus-Visible vs Focus

```html
<!-- focus: Shows on click AND keyboard -->
<button class="focus:ring-2">Shows ring on mouse click too</button>

<!-- focus-visible: Shows only on keyboard (better UX) -->
<button class="focus-visible:ring-2">Ring only for keyboard users</button>
```

## Quick Reference

| Element | Focus Style | Class |
|---------|-------------|-------|
| Buttons | Ring | `focus-visible:ring-2` |
| Links | Underline + ring | `focus-visible:ring-2 focus-visible:underline` |
| Inputs | Border | `focus:border-primary focus:ring-1` |
| Cards | Shadow | `focus-visible:ring-2` |

## Tailwind Classes

```
focus:outline-none        - Remove default (ONLY with replacement)
focus:ring-2              - Add focus ring
focus:ring-ring           - Use theme ring color
focus:ring-offset-2       - Add offset for visibility
focus-visible:*           - Only show for keyboard focus
```

## Standard Focus Pattern

```html
<!-- Standard pattern for all interactive elements -->
<button class="
  focus:outline-none
  focus-visible:ring-2
  focus-visible:ring-ring
  focus-visible:ring-offset-2
">
  Button
</button>

<!-- For inputs -->
<input class="
  border border-input
  focus:outline-none
  focus:ring-2
  focus:ring-ring
  focus:border-transparent
" />
```

## Testing

- [ ] Tab through entire page with keyboard
- [ ] Every interactive element shows focus indicator
- [ ] Focus order is logical (left-to-right, top-to-bottom)
- [ ] Focus ring has 3:1 contrast against background

## Don't Remove Default Without Replacement

```css
/* If you must remove outline */
.custom-focus:focus {
  outline: none;
  /* MUST add visible replacement */
  box-shadow: 0 0 0 2px var(--ring);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- shadcn/ui components have built-in focus styles
- Check `focus-visible:ring-2 focus-visible:ring-ring`
- Custom components must include focus states
- Test with Tab key after every component change
