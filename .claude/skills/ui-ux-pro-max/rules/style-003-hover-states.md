---
id: style-003-hover-states
title: Stable Hover States (No Layout Shift)
impact: MEDIUM
impactDescription: "Hover effects that shift layout feel janky and cause misclicks"
category: style
tags: [hover, animation, layout, interaction]
relatedRules: [ux-004-animation-performance, style-002-cursor-pointer]
platforms: [Web]
---

## Why This Matters

When hover effects change element size or position, surrounding content shifts. This feels unprofessional, can cause misclicks, and is jarring for users. Use transforms instead.

## The Problem

```html
<!-- Bad: Border on hover shifts layout -->
<div class="hover:border-2 hover:border-blue-500">
  This grows 4px on hover, shifting everything
</div>

<!-- Bad: Padding change shifts layout -->
<button class="p-2 hover:p-4">
  Jumps around
</button>

<!-- Bad: Scale without transform -->
<img class="w-48 hover:w-52">
  <!-- Not using CSS transform -->
</img>
```

## Solution

### Use Transform for Size Changes

```html
<!-- Good: Transform doesn't affect layout -->
<div class="transform hover:-translate-y-1 transition-transform">
  Lifts up smoothly
</div>

<div class="transform hover:scale-105 transition-transform">
  Grows without shifting siblings
</div>
```

### Pre-define Border Space

```html
<!-- Good: Border always exists, just transparent -->
<div class="border-2 border-transparent hover:border-blue-500 transition-colors">
  No layout shift
</div>

<!-- Good: Use ring instead of border -->
<div class="hover:ring-2 hover:ring-blue-500 transition-shadow">
  Ring doesn't affect layout
</div>
```

### Color Changes Only

```html
<!-- Good: Only color changes (no layout impact) -->
<button class="
  bg-gray-100
  hover:bg-gray-200
  transition-colors duration-150
">
  Stable hover
</button>

<!-- Good: Shadow doesn't shift layout -->
<div class="shadow hover:shadow-lg transition-shadow">
  Elevated hover
</div>
```

## Quick Reference

| Effect | Stable Method |
|--------|--------------|
| Grow/shrink | `hover:scale-*` transform |
| Lift up | `hover:-translate-y-1` |
| Border | Pre-define `border-transparent` |
| Outline | Use `ring-*` |
| Emphasis | `hover:shadow-*` |
| Color | `hover:bg-*` |

## Tailwind Classes

```
# Transform-based (stable)
hover:-translate-y-1  - Lift up
hover:scale-105       - Grow 5%
hover:scale-95        - Shrink 5%

# Color-based (stable)
hover:bg-*            - Background color
hover:text-*          - Text color
hover:shadow-*        - Shadow (no layout shift)
hover:ring-*          - Ring (no layout shift)

# Add transition
transition-transform  - Smooth transform
transition-colors     - Smooth colors
transition-shadow     - Smooth shadow
transition-all        - All properties
```

## Card Hover Pattern

```html
<!-- Good: Complete stable card hover -->
<div class="
  bg-white rounded-lg p-4
  shadow-sm hover:shadow-md
  ring-1 ring-gray-200 hover:ring-primary/20
  transform hover:-translate-y-0.5
  transition-all duration-200
  cursor-pointer
">
  Card content
</div>
```

## Button Hover Pattern

```html
<!-- Good: Button with all states -->
<button class="
  px-4 py-2 rounded
  bg-primary text-primary-foreground
  hover:bg-primary/90
  active:scale-95
  transition-all duration-150
  focus-visible:ring-2 focus-visible:ring-ring
">
  Button
</button>
```

## Testing

- [ ] Hover over elements - no content shifts
- [ ] Adjacent elements don't move
- [ ] Transitions are smooth (150-200ms)
- [ ] Active/pressed state feels responsive

## Project-Specific Notes

**BotFacebook Context:**
- Bot cards: use `hover:shadow-md hover:-translate-y-0.5`
- Message hover: `hover:bg-muted/50` only
- Buttons: shadcn has stable hover by default
- Avoid adding `hover:border-*` without transparent base
