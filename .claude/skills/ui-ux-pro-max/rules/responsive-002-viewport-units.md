---
id: responsive-002-viewport-units
title: Use dvh Instead of vh on Mobile
impact: HIGH
impactDescription: "100vh shows content behind mobile browser chrome"
category: responsive
tags: [viewport, mobile, dvh, height]
relatedRules: [ux-008-content-jump]
platforms: [Mobile, Web]
---

## Why This Matters

On mobile browsers, `100vh` includes the space behind the address bar and bottom navigation. This causes content to be hidden and requires scrolling to see the full page. Dynamic viewport units (`dvh`) account for browser chrome.

## The Problem

```html
<!-- Bad: Content hidden behind browser chrome -->
<div class="h-screen">
  <!-- On mobile, bottom of this is behind URL bar -->
  <button>This button might be hidden!</button>
</div>
```

## Solution

### Use Dynamic Viewport Height

```html
<!-- Good: Accounts for mobile browser chrome -->
<div class="min-h-dvh">
  Full height, respects browser UI
</div>

<!-- Good: For fixed full-screen layouts -->
<div class="h-dvh overflow-hidden">
  App-like full screen
</div>
```

### Fallback for Older Browsers

```css
/* Good: Fallback for browsers without dvh */
.full-height {
  min-height: 100vh; /* Fallback */
  min-height: 100dvh; /* Modern browsers */
}
```

### Tailwind v4 Classes

```html
<!-- Tailwind v4 has dvh built-in -->
<div class="min-h-dvh">Dynamic viewport height</div>
<div class="h-svh">Small viewport height (smallest)</div>
<div class="h-lvh">Large viewport height (largest)</div>
```

## Quick Reference

| Unit | Behavior | Use For |
|------|----------|---------|
| `vh` | Always 1% of initial viewport | Avoid on mobile |
| `dvh` | 1% of dynamic viewport (changes with scroll) | Mobile full-screen |
| `svh` | 1% of smallest viewport (browser chrome visible) | Safe minimum |
| `lvh` | 1% of largest viewport (browser chrome hidden) | Maximum possible |

## Testing on Mobile

```
iOS Safari:
1. Open page on iPhone
2. Notice address bar at bottom
3. Scroll - address bar hides
4. dvh adjusts, vh doesn't

Chrome Android:
1. Similar behavior
2. Address bar at top
3. dvh accounts for this
```

## Common Patterns

```html
<!-- Hero section -->
<section class="min-h-[80dvh] flex items-center">
  Hero content
</section>

<!-- Full-screen app -->
<div class="h-dvh flex flex-col">
  <header class="h-14">Header</header>
  <main class="flex-1 overflow-auto">Content</main>
  <footer class="h-16">Footer</footer>
</div>

<!-- Modal backdrop -->
<div class="fixed inset-0 h-dvh bg-black/50">
  Modal backdrop
</div>
```

## Testing

- [ ] Test on iOS Safari (most problematic)
- [ ] Test on Chrome Android
- [ ] Verify bottom content isn't hidden
- [ ] Check scrolling behavior with browser chrome

## Project-Specific Notes

**BotFacebook Context:**
- Use `min-h-dvh` for page layouts
- Mobile chat view needs `h-dvh` for proper height
- Test sidebar on mobile Safari
- Tailwind v4 supports dvh/svh/lvh natively
