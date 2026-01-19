---
id: ux-004-animation-performance
title: Animate Only Transform & Opacity
impact: MEDIUM
impactDescription: "Animating width/height/top/left causes expensive repaints"
category: ux
tags: [animation, performance, transform, gpu]
relatedRules: [ux-002-animation-timing, a11y-006-reduced-motion]
platforms: [Web]
---

## Why This Matters

Animating CSS properties like `width`, `height`, `top`, `left`, `margin` triggers browser repaints and reflows, causing janky 15fps animations. Transform and opacity use GPU acceleration for smooth 60fps.

## The Problem

```css
/* Bad: Triggers layout recalculation */
.sidebar {
  transition: width 300ms; /* Expensive! */
}
.sidebar.collapsed {
  width: 60px;
}

/* Bad: Causes reflow */
.dropdown {
  transition: height 200ms; /* Janky! */
}
```

## Solution

```css
/* Good: Use transform for movement */
.sidebar {
  transform: translateX(0);
  transition: transform 300ms ease-out;
}
.sidebar.collapsed {
  transform: translateX(-200px);
}

/* Good: Use transform for scaling */
.dropdown {
  transform: scaleY(1);
  transform-origin: top;
  transition: transform 200ms ease-out;
}
.dropdown.closed {
  transform: scaleY(0);
}

/* Good: Use opacity for fade */
.modal {
  opacity: 1;
  transition: opacity 200ms ease-out;
}
.modal.hidden {
  opacity: 0;
}
```

### Tailwind Implementation

```html
<!-- Movement: use translate -->
<div class="transform translate-x-0 transition-transform duration-200
            hover:-translate-y-1">
  Card with lift effect
</div>

<!-- Scale: use scale -->
<button class="transform scale-100 transition-transform duration-150
               active:scale-95">
  Press me
</button>

<!-- Fade: use opacity -->
<div class="opacity-100 transition-opacity duration-200
            group-hover:opacity-80">
  Fading content
</div>
```

## Quick Reference

| Effect | Use | Don't Use |
|--------|-----|-----------|
| Move element | `translate-x/y` | `left`, `right`, `margin` |
| Resize appearance | `scale` | `width`, `height` |
| Show/hide | `opacity` | `visibility` alone |
| Rotate | `rotate` | N/A |

## Tailwind Classes

```
transform         - Enable transforms
translate-x-*     - Horizontal movement
translate-y-*     - Vertical movement
scale-*           - Size scaling
rotate-*          - Rotation
opacity-*         - Fade level
transition-transform - Animate transforms only
transition-opacity   - Animate opacity only
```

## Testing

- [ ] Open DevTools Performance tab
- [ ] Animations show consistent 60fps
- [ ] No "Layout Shift" warnings
- [ ] Mobile feels smooth, not janky

## Project-Specific Notes

**BotFacebook Context:**
- shadcn/ui Sheet uses transform for slide
- Dialog uses opacity + scale for animation
- Card hover effects should use `-translate-y-1`
- Avoid animating `w-*` or `h-*` classes
