---
id: style-004-light-dark-mode
title: Light/Dark Mode Contrast Issues
impact: HIGH
impactDescription: "Styles that work in dark mode often fail in light mode"
category: style
tags: [dark-mode, light-mode, contrast, theming]
relatedRules: [a11y-001-color-contrast]
platforms: [Web]
---

## Why This Matters

Designs often look great in dark mode but fail in light mode. Glass effects become invisible, text becomes unreadable, and borders disappear. Always test both modes.

## The Problem

```html
<!-- Bad: Glass effect invisible in light mode -->
<div class="bg-white/10 backdrop-blur">
  Can't see this card in light mode
</div>

<!-- Bad: Border invisible in light mode -->
<div class="border border-white/10">
  Border disappears on white background
</div>

<!-- Bad: Text too light -->
<p class="text-gray-400">
  2.8:1 contrast, fails WCAG
</p>
```

## Solution

### Conditional Styles Per Mode

```html
<!-- Good: Different styles for each mode -->
<div class="
  bg-white/80 dark:bg-black/20
  backdrop-blur
  border border-gray-200 dark:border-white/10
">
  Works in both modes
</div>

<!-- Good: Text contrast for both modes -->
<p class="text-gray-700 dark:text-gray-300">
  Readable in both modes
</p>
<p class="text-muted-foreground">
  Uses theme variable (auto-adapts)
</p>
```

### Glass Effect for Both Modes

```html
<!-- Good: Visible glass in both modes -->
<div class="
  bg-white/80 dark:bg-gray-900/80
  backdrop-blur-xl
  border border-gray-200/50 dark:border-white/10
  shadow-lg dark:shadow-none
">
  Glass card
</div>
```

## Quick Reference

| Element | Light Mode | Dark Mode |
|---------|------------|-----------|
| Glass bg | `bg-white/80` | `bg-black/20` |
| Border | `border-gray-200` | `border-white/10` |
| Shadow | `shadow-lg` | `shadow-none` |
| Body text | `text-gray-700` | `text-gray-300` |
| Muted text | `text-gray-600` | `text-gray-400` |
| Heading | `text-gray-900` | `text-white` |

## Using Theme Variables

```html
<!-- Best: CSS variables that auto-adapt -->
<div class="bg-background text-foreground">
  Uses theme tokens
</div>

<div class="bg-card border-border">
  Card uses semantic tokens
</div>

<p class="text-muted-foreground">
  Muted text adapts automatically
</p>
```

### CSS Variables (index.css)

```css
:root {
  --background: 0 0% 100%;
  --foreground: 222 47% 11%;
  --card: 0 0% 100%;
  --border: 214 32% 91%;
  --muted-foreground: 215 16% 47%;
}

.dark {
  --background: 222 47% 11%;
  --foreground: 210 40% 98%;
  --card: 222 47% 14%;
  --border: 217 33% 17%;
  --muted-foreground: 215 20% 65%;
}
```

## Testing Checklist

| Check | Light Mode | Dark Mode |
|-------|------------|-----------|
| Glass cards visible | ✓ | ✓ |
| Borders visible | ✓ | ✓ |
| Text readable | ✓ | ✓ |
| Shadows appropriate | ✓ | ✓ |
| Input backgrounds | ✓ | ✓ |

## Testing

- [ ] Toggle between light/dark mode
- [ ] All glass effects visible in light mode
- [ ] All borders visible in both modes
- [ ] Text passes contrast check in both modes
- [ ] No invisible elements

## Tailwind Dark Mode

```html
<!-- Standard pattern -->
<div class="bg-white dark:bg-gray-900">
  <p class="text-gray-900 dark:text-gray-100">
    Adapts to mode
  </p>
</div>
```

## Project-Specific Notes

**BotFacebook Context:**
- Use shadcn/ui semantic tokens (`bg-background`, `text-foreground`)
- Check `frontend/src/index.css` for theme variables
- Test dashboard cards in both modes
- Glass effects need higher opacity in light mode
