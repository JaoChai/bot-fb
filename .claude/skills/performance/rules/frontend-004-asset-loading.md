---
id: frontend-004-asset-loading
title: Inefficient Asset Loading
impact: MEDIUM
impactDescription: "Fonts, CSS, and scripts blocking page render"
category: frontend
tags: [assets, fonts, css, preload]
relatedRules: [vitals-001-lcp, frontend-002-code-splitting]
---

## Symptom

- Flash of unstyled content (FOUC)
- Flash of invisible text (FOIT)
- Render-blocking resources in Lighthouse
- Slow First Contentful Paint (FCP)

## Root Cause

1. Render-blocking CSS/JS
2. Fonts not preloaded
3. Third-party scripts blocking
4. Large CSS files
5. No critical CSS extraction

## Diagnosis

### Quick Check

```bash
# Check render-blocking resources
npx lighthouse https://www.botjao.com --only-audits=render-blocking-resources

# Check font loading
curl -I https://www.botjao.com | grep -i font
```

### Detailed Analysis

```html
<!-- Check <head> for blocking resources -->
<!-- Bad: blocking script -->
<script src="/heavy.js"></script>

<!-- Bad: blocking stylesheet without preload -->
<link rel="stylesheet" href="/fonts.css">
```

## Measurement

```
Before: Render-blocking time > 500ms
Target: Render-blocking time < 100ms
```

## Solution

### Fix Steps

1. **Preload critical fonts**
```html
<!-- index.html -->
<head>
  <!-- Preload fonts used above the fold -->
  <link
    rel="preload"
    href="/fonts/inter-var.woff2"
    as="font"
    type="font/woff2"
    crossorigin
  />

  <!-- Font display swap to prevent FOIT -->
  <style>
    @font-face {
      font-family: 'Inter';
      src: url('/fonts/inter-var.woff2') format('woff2');
      font-display: swap;
    }
  </style>
</head>
```

2. **Async/defer scripts**
```html
<!-- Non-critical scripts -->
<script src="/analytics.js" async></script>

<!-- Scripts that need DOM -->
<script src="/interactive.js" defer></script>
```

3. **Preconnect to external origins**
```html
<head>
  <!-- Preconnect to API domain -->
  <link rel="preconnect" href="https://api.botjao.com" />

  <!-- Preconnect to CDN -->
  <link rel="preconnect" href="https://cdn.botjao.com" crossorigin />

  <!-- DNS prefetch for third parties -->
  <link rel="dns-prefetch" href="https://fonts.googleapis.com" />
</head>
```

4. **Inline critical CSS**
```html
<head>
  <!-- Critical CSS inline -->
  <style>
    /* Above-the-fold styles */
    body { margin: 0; font-family: Inter, sans-serif; }
    .header { height: 64px; background: #fff; }
  </style>

  <!-- Full CSS async -->
  <link
    rel="preload"
    href="/styles.css"
    as="style"
    onload="this.onload=null;this.rel='stylesheet'"
  />
</head>
```

5. **Vite optimization**
```typescript
// vite.config.ts
export default defineConfig({
  build: {
    cssCodeSplit: true,  // Split CSS per route
    assetsInlineLimit: 4096,  // Inline small assets
  },
  css: {
    devSourcemap: true,
  },
});
```

6. **Lazy load third-party scripts**
```typescript
// Load analytics only after page load
useEffect(() => {
  if (typeof window !== 'undefined') {
    const script = document.createElement('script');
    script.src = '/analytics.js';
    script.async = true;
    document.body.appendChild(script);
  }
}, []);
```

### Asset Loading Priority

| Asset | Strategy | Priority |
|-------|----------|----------|
| Critical CSS | Inline | Highest |
| Fonts (above fold) | Preload | High |
| Main JS | Default | High |
| Images (hero) | eager + fetchpriority | High |
| Non-critical CSS | Async load | Medium |
| Analytics | Async/defer | Low |
| Images (below fold) | Lazy | Low |

## Verification

```bash
# Run Lighthouse audit
npx lighthouse https://www.botjao.com --only-categories=performance

# Check render-blocking
# Should show 0 render-blocking resources

# Check font loading
# No FOIT in browser
```

## Prevention

- Use font-display: swap
- Preload critical assets
- Async all third-party scripts
- Review new script additions
- Test with slow 3G throttling

## Project-Specific Notes

**BotFacebook Context:**
- Font: Inter (variable font, preloaded)
- API: api.botjao.com (preconnect)
- CDN: Cloudflare (auto-optimizes)
- Analytics: Load after interaction
