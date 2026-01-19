---
id: ux-008-content-jump
title: Prevent Layout Shift (CLS)
impact: HIGH
impactDescription: "Content jumping unexpectedly causes misclicks and frustration"
category: ux
tags: [cls, layout-shift, images, loading]
relatedRules: [ux-001-loading-states, responsive-002-viewport-units]
platforms: [Web]
---

## Why This Matters

When content loads and pushes other content around, users click the wrong thing, lose their reading position, and have a terrible experience. Google also penalizes CLS in Core Web Vitals.

## The Problem

```html
<!-- Bad: Image loads and pushes content down -->
<article>
  <img src="/large-image.jpg" /> <!-- No dimensions! -->
  <p>This paragraph jumps down when image loads</p>
</article>

<!-- Bad: Dynamic content with no reserved space -->
<div>
  {isLoaded && <AdBanner />} <!-- Everything below shifts -->
</div>
```

## Solution

### Reserve Space for Images

```html
<!-- Good: Fixed aspect ratio -->
<div class="aspect-video relative">
  <img
    src="/image.jpg"
    alt="Description"
    class="absolute inset-0 w-full h-full object-cover"
    loading="lazy"
  />
</div>

<!-- Good: Explicit dimensions -->
<img
  src="/image.jpg"
  width="800"
  height="450"
  class="w-full h-auto"
  alt="Description"
/>
```

### Reserve Space for Dynamic Content

```tsx
// Good: Fixed height container
<div className="min-h-[200px]">
  {isLoading ? <Skeleton className="h-full" /> : <Content />}
</div>

// Good: Grid with fixed slots
<div className="grid grid-cols-3 gap-4">
  {isLoading
    ? [...Array(6)].map((_, i) => <Skeleton key={i} className="h-48" />)
    : items.map(item => <Card item={item} />)
  }
</div>
```

### Font Loading

```css
/* Good: Prevent FOUT (Flash of Unstyled Text) */
@font-face {
  font-family: 'CustomFont';
  src: url('/font.woff2') format('woff2');
  font-display: swap; /* Show fallback immediately */
}

/* Good: Size-matched fallback */
body {
  font-family: 'CustomFont', system-ui, sans-serif;
  /* Use similar metrics for fallback */
}
```

## Quick Reference

| Content Type | Solution |
|--------------|----------|
| Images | `aspect-*` or explicit width/height |
| Video | `aspect-video` container |
| Ads/Banners | Fixed `min-h-[*]` container |
| Cards/Lists | Skeleton with same height |
| Fonts | `font-display: swap` |
| Lazy content | Reserved space + skeleton |

## Tailwind Classes

```
aspect-video      - 16:9 ratio
aspect-square     - 1:1 ratio
aspect-[4/3]      - Custom ratio
min-h-[200px]     - Reserve minimum height
object-cover      - Image fills container
object-contain    - Image fits within
```

## Testing

- [ ] Open DevTools > Lighthouse > Performance
- [ ] Check CLS score (should be < 0.1)
- [ ] Watch for content shifts on slow connection
- [ ] Test with image caching disabled

## Measuring CLS

```javascript
// Log CLS in console
new PerformanceObserver((list) => {
  for (const entry of list.getEntries()) {
    console.log('CLS:', entry.value);
  }
}).observe({ type: 'layout-shift', buffered: true });
```

## Project-Specific Notes

**BotFacebook Context:**
- Use `<LazyImage>` component with aspect ratio
- Avatar images have fixed dimensions
- Dashboard cards use grid with fixed heights
- Use skeleton loading for lists
