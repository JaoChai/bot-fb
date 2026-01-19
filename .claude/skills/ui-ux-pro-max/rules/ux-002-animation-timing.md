---
id: ux-002-animation-timing
title: Animation Duration 150-300ms
impact: MEDIUM
impactDescription: "Slow animations feel sluggish, fast ones feel jarring"
category: ux
tags: [animation, transition, timing, performance]
relatedRules: [ux-004-animation-performance, a11y-006-reduced-motion]
platforms: [All]
---

## Why This Matters

Animations that are too slow (>500ms) make your UI feel sluggish and unresponsive. Too fast (<100ms) and users can't perceive them, making state changes jarring.

## The Problem

```css
/* Bad: Way too slow */
.button {
  transition: all 1000ms; /* Feels like slow motion */
}

/* Bad: No transition */
.button:hover {
  background: blue; /* Instant jump is jarring */
}
```

## Solution

```css
/* Good: Micro-interactions 150-200ms */
.button {
  transition: background-color 150ms ease-out,
              transform 150ms ease-out;
}

/* Good: Larger transitions 200-300ms */
.modal {
  transition: opacity 200ms ease-out,
              transform 200ms ease-out;
}
```

### Tailwind Duration Classes

```html
<!-- Micro-interactions -->
<button class="transition-colors duration-150 hover:bg-gray-100">
  Click me
</button>

<!-- Standard transitions -->
<div class="transition-all duration-200 ease-out">
  Content
</div>

<!-- Larger elements -->
<div class="transition-transform duration-300 ease-in-out">
  Modal
</div>
```

## Quick Reference

| Animation Type | Duration | Easing |
|---------------|----------|--------|
| Hover effects | 150ms | ease-out |
| Button press | 100ms | ease-out |
| Dropdown open | 200ms | ease-out |
| Modal appear | 200-250ms | ease-out |
| Page transition | 300ms | ease-in-out |
| Dismiss/exit | 150ms | ease-in |

## Tailwind Classes

```
duration-75    - 75ms (very fast)
duration-100   - 100ms (fast)
duration-150   - 150ms (micro) ✓
duration-200   - 200ms (standard) ✓
duration-300   - 300ms (modal) ✓
duration-500   - 500ms (MAX for UI)

ease-in        - Start slow (for exits)
ease-out       - End slow (for enters) ✓
ease-in-out    - Smooth both ways
```

## Testing

- [ ] Hover states feel instant but smooth
- [ ] Modals don't feel slow to open
- [ ] No animation exceeds 500ms
- [ ] Animations feel responsive, not delayed

## Project-Specific Notes

**BotFacebook Context:**
- Default: `transition-colors duration-200`
- Hover states: `duration-150`
- Modals/sheets: `duration-200` (shadcn default)
- Never use `duration-700` or higher for UI
