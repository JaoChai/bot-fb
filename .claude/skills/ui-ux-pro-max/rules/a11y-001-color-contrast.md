---
id: a11y-001-color-contrast
title: Minimum 4.5:1 Color Contrast
impact: CRITICAL
impactDescription: "Low contrast text is unreadable for ~8% of users with vision issues"
category: a11y
tags: [contrast, wcag, vision, accessibility]
relatedRules: [style-004-light-dark-mode]
platforms: [All]
---

## Why This Matters

Low contrast text is unreadable in bright environments and for users with vision impairments (~300 million people worldwide). WCAG 2.1 requires 4.5:1 for normal text, 3:1 for large text.

## The Problem

```html
<!-- Bad: Gray on gray (2.8:1 ratio) -->
<p class="text-gray-400 bg-gray-100">
  Hard to read text
</p>

<!-- Bad: Light text on light background -->
<p class="text-slate-300 bg-white">
  Almost invisible
</p>
```

## Solution

```html
<!-- Good: Strong contrast (7:1+) -->
<p class="text-gray-900 bg-white">
  Easy to read
</p>

<!-- Good: Muted but accessible (4.5:1) -->
<p class="text-gray-600 bg-white">
  Still readable muted text
</p>

<!-- Good: Dark mode -->
<p class="text-gray-100 bg-gray-900">
  Light on dark
</p>
```

## Quick Reference

| Text Type | Min Ratio | Example |
|-----------|-----------|---------|
| Body text | 4.5:1 | `text-gray-700` on white |
| Large text (18px+) | 3:1 | `text-gray-600` on white |
| UI components | 3:1 | Borders, icons |
| AAA compliance | 7:1 | `text-gray-900` on white |

## Safe Color Combinations

### Light Mode (white background)

| Purpose | Class | Ratio |
|---------|-------|-------|
| Body text | `text-slate-900` | 14.4:1 |
| Body text | `text-gray-700` | 8.5:1 |
| Muted text | `text-gray-600` | 5.7:1 |
| Minimum muted | `text-gray-500` | 4.6:1 |
| **FAIL** | `text-gray-400` | 2.8:1 |

### Dark Mode (gray-900 background)

| Purpose | Class | Ratio |
|---------|-------|-------|
| Body text | `text-white` | 15.5:1 |
| Body text | `text-gray-100` | 13.8:1 |
| Muted text | `text-gray-300` | 9.0:1 |
| Minimum muted | `text-gray-400` | 5.4:1 |

## Testing Tools

```bash
# Chrome DevTools
1. Inspect element
2. View "Contrast" in Styles panel
3. Shows ratio and pass/fail

# Online checker
https://webaim.org/resources/contrastchecker/
```

## Tailwind Classes

```
text-gray-900    - Highest contrast (light mode)
text-gray-700    - Good body text
text-gray-600    - Muted but accessible
text-gray-500    - Minimum for muted
text-gray-400    - TOO LOW, avoid for text
```

## Testing

- [ ] Check contrast in DevTools for all text
- [ ] Body text is 4.5:1 or higher
- [ ] Muted/secondary text is still 4.5:1
- [ ] Test in both light and dark modes

## Project-Specific Notes

**BotFacebook Context:**
- Use `text-foreground` for body text
- Use `text-muted-foreground` for secondary (pre-checked contrast)
- Avoid raw `text-gray-400` for text content
- Check contrast when customizing shadcn/ui themes
