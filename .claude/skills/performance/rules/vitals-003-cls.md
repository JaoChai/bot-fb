---
id: vitals-003-cls
title: Cumulative Layout Shift (CLS)
impact: MEDIUM
impactDescription: "Visual instability causing poor user experience"
category: vitals
tags: [core-web-vitals, cls, layout-shift, ux]
relatedRules: [frontend-003-image-optimization, vitals-001-lcp]
---

## Symptom

- Elements jumping around during load
- Accidentally clicking wrong elements
- Content shifting after images load
- Ads or banners pushing content down
- Text reflowing after font loads

## Root Cause

1. Images without dimensions
2. Ads/embeds without reserved space
3. Dynamically injected content
4. Web fonts causing reflow
5. Animations triggering layout

## Diagnosis

### Quick Check

```javascript
// Measure CLS in browser
new PerformanceObserver((list) => {
  let cls = 0;
  for (const entry of list.getEntries()) {
    if (!entry.hadRecentInput) {
      cls += entry.value;
    }
  }
  console.log('CLS:', cls);
}).observe({ type: 'layout-shift', buffered: true });
```

### Detailed Analysis

```bash
# Chrome DevTools
# Performance tab > Enable "Layout Shift Regions"
# Record page load and look for highlighted shifts

# Lighthouse
npx lighthouse https://www.botjao.com --only-audits=cumulative-layout-shift
```

## Measurement

```
Before: CLS > 0.1
Target: CLS < 0.1 (Good), < 0.05 (Great)
```

## Solution

### Fix Steps

1. **Always set image dimensions**
```tsx
// Bad: No dimensions
<img src="/photo.jpg" alt="Photo" />

// Good: Explicit dimensions
<img
  src="/photo.jpg"
  alt="Photo"
  width={800}
  height={600}
/>

// Good: Aspect ratio with CSS
<img
  src="/photo.jpg"
  alt="Photo"
  className="aspect-video w-full"
/>
```

2. **Reserve space for dynamic content**
```tsx
// Bad: Content appears and pushes things down
{isLoaded && <Banner />}

// Good: Reserve space
<div className="min-h-[100px]">
  {isLoaded ? <Banner /> : <BannerSkeleton />}
</div>

// Using skeleton loader
function MessageList() {
  if (isLoading) {
    return <MessageListSkeleton count={5} />;
  }
  return <>{messages.map(m => <Message key={m.id} />)}</>;
}
```

3. **Prevent font swap shifts**
```css
/* Use font-display: optional for minimal shift */
@font-face {
  font-family: 'CustomFont';
  src: url('/font.woff2') format('woff2');
  font-display: optional;  /* No swap = no shift */
}

/* Or use size-adjust for swap */
@font-face {
  font-family: 'CustomFont';
  src: url('/font.woff2') format('woff2');
  font-display: swap;
  size-adjust: 100.6%;  /* Match fallback metrics */
}
```

4. **Use transform for animations**
```css
/* Bad: Animating layout properties */
.animate {
  animation: grow 0.3s;
}
@keyframes grow {
  from { height: 0; margin-top: 20px; }
  to { height: 100px; margin-top: 0; }
}

/* Good: Use transform */
.animate {
  animation: grow 0.3s;
}
@keyframes grow {
  from { transform: scaleY(0); opacity: 0; }
  to { transform: scaleY(1); opacity: 1; }
}
```

5. **Handle async content**
```tsx
// Toasts/notifications - use fixed positioning
<div className="fixed bottom-4 right-4">
  <Toast />
</div>

// Infinite scroll - add below existing content
<div className="flex flex-col">
  {items.map(item => <Item key={item.id} />)}
  {hasMore && <LoadMoreButton />}
</div>
```

6. **Reserve space for embeds**
```tsx
// YouTube embed with aspect ratio
<div className="aspect-video w-full bg-gray-100">
  <iframe
    src="https://youtube.com/embed/..."
    className="w-full h-full"
    loading="lazy"
  />
</div>

// Third-party widget
<div className="min-h-[300px]">
  <ThirdPartyWidget />
</div>
```

### CLS Prevention Checklist

| Element | Solution |
|---------|----------|
| Images | Set width/height or aspect-ratio |
| Videos/embeds | Container with aspect-ratio |
| Ads | Reserved min-height |
| Fonts | font-display: optional or size-adjust |
| Dynamic content | Skeleton loaders |
| Animations | Use transform, not layout |
| Toasts | Fixed positioning |

## Verification

```javascript
// Log layout shifts
new PerformanceObserver((list) => {
  list.getEntries().forEach(entry => {
    if (entry.value > 0.01) {
      console.log('Layout shift:', entry.value, entry.sources);
    }
  });
}).observe({ type: 'layout-shift', buffered: true });

// Lighthouse CLS audit
npx lighthouse https://www.botjao.com --only-audits=cumulative-layout-shift
```

## Prevention

- Set dimensions on all images
- Use skeleton loaders
- Reserve space for async content
- Test with slow network
- Monitor CLS in production

## Project-Specific Notes

**BotFacebook Context:**
- CLS target: < 0.1
- Avatar images: Use Avatar component with fallback
- Chat messages: Skeleton during load
- Dashboard widgets: Min-height reserved
- Fonts: Inter with font-display: swap
