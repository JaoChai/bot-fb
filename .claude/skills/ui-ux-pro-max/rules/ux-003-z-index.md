---
id: ux-003-z-index
title: Z-Index Scale System
impact: HIGH
impactDescription: "Random z-index values cause elements to hide behind others"
category: ux
tags: [z-index, stacking, layers, modal]
relatedRules: [style-004-light-dark-mode]
platforms: [Web]
---

## Why This Matters

Without a z-index system, you end up with `z-[9999]` everywhere, elements hiding behind each other, and debugging nightmares.

## The Problem

```css
/* Bad: Random z-index values */
.dropdown { z-index: 100; }
.modal { z-index: 999; }
.toast { z-index: 9999; }
.tooltip { z-index: 99999; } /* Arms race! */

/* Result: Modal hides behind toast, tooltip behind modal */
```

## Solution

### Define a Scale System

```css
/* Good: Consistent scale */
:root {
  --z-base: 0;
  --z-dropdown: 10;
  --z-sticky: 20;
  --z-fixed: 30;
  --z-modal-backdrop: 40;
  --z-modal: 50;
  --z-toast: 60;
}
```

### Tailwind Implementation

```html
<!-- Base content -->
<div class="relative z-0">Content</div>

<!-- Dropdown menus -->
<div class="z-10">Dropdown</div>

<!-- Sticky headers -->
<header class="sticky top-0 z-20">Header</header>

<!-- Fixed navigation -->
<nav class="fixed z-30">Nav</nav>

<!-- Modal backdrop -->
<div class="fixed inset-0 z-40 bg-black/50">Backdrop</div>

<!-- Modal content -->
<div class="fixed z-50">Modal</div>

<!-- Toast notifications -->
<div class="fixed z-60">Toast</div>
```

## Quick Reference

| Layer | z-index | Use For |
|-------|---------|---------|
| Base | 0 | Normal content |
| Elevated | 1-9 | Cards, subtle elevation |
| Dropdown | 10 | Menus, autocomplete |
| Sticky | 20 | Sticky headers |
| Fixed | 30 | Fixed nav, FAB |
| Modal backdrop | 40 | Overlay |
| Modal | 50 | Modal/dialog content |
| Toast | 60 | Notifications |

## Tailwind Classes

```
z-0   - Base content
z-10  - Dropdowns, tooltips
z-20  - Sticky elements
z-30  - Fixed nav
z-40  - Modal backdrop
z-50  - Modal content
z-60  - Toast (may need custom)
```

## Stacking Context Gotcha

```tsx
// Bad: Parent creates new stacking context
<div className="relative z-10">
  <div className="z-50">This won't escape parent!</div>
</div>

// Good: Use portal for modals
<Portal>
  <div className="fixed z-50">Modal escapes parent context</div>
</Portal>
```

## Testing

- [ ] Dropdowns appear above content
- [ ] Modals appear above everything except toasts
- [ ] Toasts visible even when modal is open
- [ ] No elements randomly hidden

## Project-Specific Notes

**BotFacebook Context:**
- shadcn/ui handles z-index for Dialog, Sheet, Tooltip
- Sonner (toast) uses z-[100] by default
- Use Radix Portal for custom overlays
- Check `tailwind.config.ts` for z-index scale
