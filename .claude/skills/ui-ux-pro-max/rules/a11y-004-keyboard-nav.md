---
id: a11y-004-keyboard-nav
title: Logical Keyboard Navigation
impact: CRITICAL
impactDescription: "Keyboard users get trapped or confused by illogical tab order"
category: a11y
tags: [keyboard, tabindex, focus, navigation]
relatedRules: [a11y-002-focus-states]
platforms: [Web]
---

## Why This Matters

Users who can't use a mouse rely on Tab, Enter, and arrow keys to navigate. If tab order is confusing, focus gets trapped in modals, or elements are unreachable, they can't use your site.

## The Problem

```html
<!-- Bad: Visual order doesn't match DOM order -->
<div class="flex flex-row-reverse">
  <button>Third visually, first in DOM</button>
  <button>Second</button>
  <button>First visually, third in DOM</button>
</div>

<!-- Bad: Random tabindex values -->
<button tabindex="5">Why am I fifth?</button>
<button tabindex="2">Second?</button>
<button tabindex="99">Last!</button>

<!-- Bad: Non-interactive element made focusable -->
<div tabindex="0" onclick="doSomething()">
  This should be a button
</div>
```

## Solution

### Natural Tab Order

```html
<!-- Good: DOM order matches visual order -->
<nav>
  <a href="/">Home</a>
  <a href="/about">About</a>
  <a href="/contact">Contact</a>
</nav>

<!-- Good: Use flexbox order with matching DOM -->
<div class="flex">
  <button>First</button>
  <button>Second</button>
  <button>Third</button>
</div>
```

### Focus Trapping in Modals

```tsx
// Good: Use Radix Dialog (handles focus trap)
import { Dialog } from '@radix-ui/react-dialog';

function Modal({ children }) {
  return (
    <Dialog>
      <DialogContent>
        {/* Focus is trapped inside */}
        {children}
        {/* Escape returns focus to trigger */}
      </DialogContent>
    </Dialog>
  );
}
```

### Skip Links

```html
<!-- Good: Skip to main content -->
<body>
  <a
    href="#main"
    class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:p-4 focus:bg-white"
  >
    Skip to main content
  </a>
  <nav><!-- Long navigation --></nav>
  <main id="main">
    <!-- Content -->
  </main>
</body>
```

## Quick Reference

| tabindex Value | Behavior |
|---------------|----------|
| Not set | Natural order (default) |
| `0` | Adds to natural tab order |
| `-1` | Programmatically focusable, not tabbable |
| `1+` | **AVOID** - Creates confusing order |

## Tailwind Classes

```
sr-only           - Visually hidden, screen reader visible
not-sr-only       - Make visible
focus:not-sr-only - Visible when focused (skip links)
```

## Testing

- [ ] Tab through entire page start to finish
- [ ] Tab order matches visual reading order
- [ ] Can reach all interactive elements
- [ ] Modal traps focus, Escape closes
- [ ] No focus traps outside modals

## Keyboard Shortcuts Reference

| Key | Action |
|-----|--------|
| Tab | Move to next focusable |
| Shift+Tab | Move to previous |
| Enter/Space | Activate button |
| Escape | Close modal/dropdown |
| Arrow keys | Navigate within component |

## Project-Specific Notes

**BotFacebook Context:**
- shadcn/ui Dialog, Sheet, DropdownMenu handle focus trapping
- Add skip link if header nav has many items
- Test sidebar + main content tab flow
- Use Radix primitives for accessible components
