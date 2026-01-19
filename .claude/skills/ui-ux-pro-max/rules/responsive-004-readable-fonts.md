---
id: responsive-004-readable-fonts
title: Minimum 16px Body Text on Mobile
impact: HIGH
impactDescription: "Small text is unreadable and forces users to zoom"
category: responsive
tags: [typography, font-size, mobile, readability]
relatedRules: [a11y-001-color-contrast]
platforms: [Mobile, Web]
---

## Why This Matters

Text smaller than 16px is hard to read on mobile screens held at arm's length. It also triggers iOS Safari auto-zoom on input focus, causing jarring UX. Google recommends 16px minimum for mobile readability.

## The Problem

```html
<!-- Bad: Too small on mobile -->
<p class="text-xs">10px text is unreadable</p>
<p class="text-sm">14px is still too small for body</p>

<!-- Bad: Input triggers zoom on iOS -->
<input class="text-sm" /> <!-- 14px causes zoom on focus -->
```

## Solution

### Body Text

```html
<!-- Good: 16px (1rem) minimum -->
<p class="text-base">
  This is readable on all devices
</p>

<!-- Good: Responsive scaling -->
<p class="text-sm md:text-base">
  14px on desktop, 16px on mobile
</p>
```

### Input Fields

```html
<!-- Good: 16px prevents iOS zoom -->
<input class="text-base" />

<!-- Good: At least 16px for all form elements -->
<select class="text-base">...</select>
<textarea class="text-base">...</textarea>
```

### Responsive Type Scale

```html
<!-- Mobile-first type scale -->
<h1 class="text-2xl md:text-4xl">Heading 1</h1>
<h2 class="text-xl md:text-3xl">Heading 2</h2>
<h3 class="text-lg md:text-2xl">Heading 3</h3>
<p class="text-base">Body text</p>
<span class="text-sm text-muted-foreground">Caption</span>
```

## Quick Reference

| Element | Minimum Mobile | Desktop |
|---------|---------------|---------|
| Body text | 16px (text-base) | 16px |
| Inputs | 16px (text-base) | 14px OK |
| Captions | 14px (text-sm) | 12px OK |
| Labels | 14px (text-sm) | 14px |
| Buttons | 16px (text-base) | 14px OK |

## Tailwind Classes

```
text-xs   - 12px (captions only, not body)
text-sm   - 14px (desktop only)
text-base - 16px (minimum for mobile body)
text-lg   - 18px
text-xl   - 20px
```

## Line Height for Readability

```html
<!-- Good: Adequate line height -->
<p class="text-base leading-relaxed">
  This has 1.625 line height, very readable
</p>

<!-- Good: For long-form content -->
<article class="prose">
  Tailwind prose class handles typography
</article>
```

## Max Width for Readability

```html
<!-- Good: Limit line length -->
<p class="text-base max-w-prose">
  Lines of 65-75 characters are optimal
</p>

<!-- max-w-prose = 65ch (characters) -->
```

## Testing

- [ ] Test on real mobile device
- [ ] Body text is readable without zooming
- [ ] Inputs don't trigger zoom on iOS
- [ ] Line length is comfortable (not too wide)

## iOS Zoom Prevention

```css
/* Prevent zoom on input focus */
input, select, textarea {
  font-size: 16px; /* Minimum to prevent zoom */
}

/* Or use this meta tag (not recommended, blocks user zoom) */
/* <meta name="viewport" content="...maximum-scale=1"> */
```

## Project-Specific Notes

**BotFacebook Context:**
- Use `text-base` for all body text and inputs
- shadcn/ui Input uses `text-sm` - consider overriding for mobile
- Chat messages should use `text-base`
- Small text OK for timestamps, but use `text-sm` not `text-xs`
