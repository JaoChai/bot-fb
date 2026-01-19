---
id: vitals-001-lcp
title: Largest Contentful Paint (LCP)
impact: HIGH
impactDescription: "Slow main content rendering affecting user perception"
category: vitals
tags: [core-web-vitals, lcp, performance, ux]
relatedRules: [frontend-003-image-optimization, frontend-004-asset-loading]
---

## Symptom

- Users see blank/loading screen for too long
- Lighthouse LCP score > 2.5s
- Main content appears slowly
- Poor user perception of speed

## Root Cause

1. Large hero images not optimized
2. Render-blocking resources
3. Slow server response time (TTFB)
4. Client-side rendering delay
5. Fonts blocking text render

## Diagnosis

### Quick Check

```bash
# Run Lighthouse
npx lighthouse https://www.botjao.com --only-categories=performance

# Check LCP element in Chrome DevTools
# Performance tab > Timings > LCP
```

### Detailed Analysis

```javascript
// Measure LCP in browser
new PerformanceObserver((entryList) => {
  for (const entry of entryList.getEntries()) {
    console.log('LCP:', entry.startTime, entry.element);
  }
}).observe({ type: 'largest-contentful-paint', buffered: true });
```

## Measurement

```
Before: LCP > 2.5s
Target: LCP < 2.5s (Good), < 1.8s (Great)
```

## Solution

### Fix Steps

1. **Optimize LCP element (usually hero image)**
```tsx
// Prioritize hero image loading
<img
  src="/hero.webp"
  alt="Hero"
  width={1200}
  height={600}
  loading="eager"
  fetchpriority="high"
  decoding="async"
/>
```

2. **Preload critical resources**
```html
<head>
  <!-- Preload LCP image -->
  <link
    rel="preload"
    as="image"
    href="/hero.webp"
    fetchpriority="high"
  />

  <!-- Preload critical font -->
  <link
    rel="preload"
    as="font"
    href="/fonts/inter.woff2"
    type="font/woff2"
    crossorigin
  />
</head>
```

3. **Reduce server response time**
```php
// Use response caching
Route::get('/', function () {
    return Cache::remember('homepage', 300, function () {
        return view('welcome');
    });
})->middleware('cache.headers:public;max_age=300');
```

4. **Inline critical CSS**
```html
<head>
  <style>
    /* Critical CSS for above-the-fold */
    .hero { min-height: 400px; background: #f0f0f0; }
    .hero-title { font-size: 2rem; }
  </style>
</head>
```

5. **Server-side rendering for critical content**
```tsx
// For React - use SSR or pre-rendering for landing pages
// Or ensure initial HTML has content skeleton

// Bad: Empty div that fills after JS
<div id="root"></div>

// Better: Pre-rendered content
<div id="root">
  <div class="hero">
    <h1>Welcome</h1>
  </div>
</div>
```

6. **Optimize font loading**
```css
@font-face {
  font-family: 'Inter';
  src: url('/fonts/inter.woff2') format('woff2');
  font-display: swap;  /* Show fallback immediately */
}
```

### LCP Optimization Checklist

| Check | Action |
|-------|--------|
| LCP element identified | DevTools > Performance > LCP |
| Hero image optimized | WebP, compressed, sized correctly |
| Hero image preloaded | `<link rel="preload">` in head |
| TTFB < 200ms | Server caching, CDN |
| No render-blocking CSS | Critical CSS inline |
| Font display swap | `font-display: swap` |

## Verification

```bash
# Run Web Vitals check
npx web-vitals-cli https://www.botjao.com

# Lighthouse CI
npx lhci autorun --collect.url=https://www.botjao.com

# Real User Monitoring
# Check Analytics > Core Web Vitals
```

## Prevention

- Test LCP on every deploy
- Set LCP budget in CI
- Monitor real user metrics
- Use CDN for global users
- Optimize images before commit

## Project-Specific Notes

**BotFacebook Context:**
- LCP target: < 2.5s
- Critical pages: Landing, Dashboard
- CDN: Cloudflare (auto-optimization)
- Hero images: WebP, max 200KB
- Fonts: Inter (preloaded, swap)
