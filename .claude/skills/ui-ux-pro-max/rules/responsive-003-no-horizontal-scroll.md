---
id: responsive-003-no-horizontal-scroll
title: No Horizontal Scroll on Mobile
impact: HIGH
impactDescription: "Horizontal scrollbars on mobile break the user experience"
category: responsive
tags: [overflow, mobile, scroll, layout]
relatedRules: [responsive-004-readable-fonts]
platforms: [Mobile, Web]
---

## Why This Matters

Horizontal scrolling on mobile is unexpected, awkward, and indicates broken responsive design. Users expect vertical scrolling only. Even a few pixels of horizontal overflow looks unprofessional.

## The Problem

```html
<!-- Bad: Fixed width larger than viewport -->
<img src="wide-image.jpg" width="1200" />

<!-- Bad: Table without responsive handling -->
<table class="w-[800px]">...</table>

<!-- Bad: Long text without word wrap -->
<pre>verylongstringwithoutspacesthatoverflows...</pre>

<!-- Bad: Negative margins causing overflow -->
<div class="-mx-8">This extends past viewport</div>
```

## Solution

### Prevent Image Overflow

```html
<!-- Good: Images scale down -->
<img src="wide-image.jpg" class="max-w-full h-auto" />

<!-- Good: As default in CSS reset -->
img, video {
  max-width: 100%;
  height: auto;
}
```

### Handle Tables

```html
<!-- Good: Horizontal scroll container for tables -->
<div class="overflow-x-auto">
  <table class="min-w-[600px]">...</table>
</div>

<!-- Good: Card layout on mobile -->
<div class="hidden md:table">Desktop table</div>
<div class="md:hidden space-y-4">
  <!-- Cards for mobile -->
</div>
```

### Handle Long Text

```html
<!-- Good: Break long words -->
<p class="break-words">
  longunbreakablestringishandledproperly
</p>

<!-- Good: For code/pre -->
<pre class="overflow-x-auto whitespace-pre-wrap">
  Long code block
</pre>
```

### Prevent Layout Overflow

```html
<!-- Good: Contain children -->
<div class="overflow-x-hidden">
  <div class="-mx-8">Safe negative margin</div>
</div>

<!-- Good: On body/html for safety -->
<html class="overflow-x-hidden">
```

## Quick Reference

| Cause | Solution |
|-------|----------|
| Wide images | `max-w-full h-auto` |
| Wide tables | `overflow-x-auto` wrapper |
| Long text | `break-words` or `break-all` |
| Fixed widths | Use `max-w-*` instead |
| Negative margins | Contain with `overflow-x-hidden` |
| Iframe/embed | Responsive wrapper |

## Tailwind Classes

```
max-w-full       - Never wider than parent
overflow-x-auto   - Horizontal scroll when needed
overflow-x-hidden - Hide horizontal overflow
break-words       - Break long words
break-all         - Break at any character
whitespace-pre-wrap - Wrap pre content
```

## Global Prevention

```css
/* In index.css - safety net */
html, body {
  overflow-x: hidden;
}

img, video, iframe {
  max-width: 100%;
  height: auto;
}
```

## Testing

- [ ] Test at 320px width (smallest phone)
- [ ] No horizontal scrollbar visible
- [ ] All content reachable without horizontal scroll
- [ ] DevTools mobile emulation

## Debug Script

```javascript
// Find elements causing horizontal scroll
document.querySelectorAll('*').forEach(el => {
  if (el.offsetWidth > document.documentElement.offsetWidth) {
    console.log('Overflow:', el, el.offsetWidth);
  }
});
```

## Project-Specific Notes

**BotFacebook Context:**
- Add `overflow-x-hidden` to root layout
- Conversation list should scroll vertically only
- Message content may need `break-words`
- Tables in KB should use scroll wrapper
