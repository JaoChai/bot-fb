---
id: frontend-003-image-optimization
title: Unoptimized Images
impact: HIGH
impactDescription: "Large images causing slow page loads and high bandwidth"
category: frontend
tags: [images, optimization, lazy-loading, webp]
relatedRules: [vitals-001-lcp, frontend-001-bundle-size]
---

## Symptom

- Images loading slowly
- Large image files in network tab
- High bandwidth usage
- Poor LCP scores
- Layout shifts from images

## Root Cause

1. Uncompressed images
2. Wrong image format (PNG instead of WebP)
3. No lazy loading
4. Missing width/height attributes
5. Not using responsive images
6. Loading large images for small displays

## Diagnosis

### Quick Check

```bash
# Check image sizes in public folder
find frontend/public -name "*.png" -o -name "*.jpg" | xargs ls -lh

# Check for WebP usage
find frontend/src -name "*.webp" | wc -l
```

### Detailed Analysis

```typescript
// Check image loading in Network tab
// Filter by Img type
// Look for images > 100KB
```

## Measurement

```
Before: Images > 500KB, no lazy loading
Target: Images < 100KB, lazy load below fold
```

## Solution

### Fix Steps

1. **Use modern formats**
```tsx
// Use WebP with fallback
<picture>
  <source srcSet="/image.webp" type="image/webp" />
  <source srcSet="/image.jpg" type="image/jpeg" />
  <img src="/image.jpg" alt="Description" />
</picture>

// Or just WebP (wide support now)
<img src="/image.webp" alt="Description" />
```

2. **Add lazy loading**
```tsx
// Native lazy loading
<img
  src="/image.webp"
  alt="Description"
  loading="lazy"
  decoding="async"
/>

// For images above the fold (LCP)
<img
  src="/hero.webp"
  alt="Hero"
  loading="eager"
  fetchpriority="high"
/>
```

3. **Always set dimensions**
```tsx
// Prevents layout shift
<img
  src="/image.webp"
  alt="Description"
  width={800}
  height={600}
  className="w-full h-auto"
/>
```

4. **Use responsive images**
```tsx
<img
  src="/image-800.webp"
  srcSet="
    /image-400.webp 400w,
    /image-800.webp 800w,
    /image-1200.webp 1200w
  "
  sizes="(max-width: 600px) 400px, (max-width: 1200px) 800px, 1200px"
  alt="Responsive image"
/>
```

5. **Optimize avatar/profile images**
```tsx
// Use Avatar component with fallback
import { Avatar, AvatarImage, AvatarFallback } from '@/components/ui/avatar';

<Avatar>
  <AvatarImage
    src={user.avatar}
    alt={user.name}
    loading="lazy"
  />
  <AvatarFallback>{user.initials}</AvatarFallback>
</Avatar>
```

6. **Build-time optimization**
```typescript
// vite.config.ts
import viteImagemin from 'vite-plugin-imagemin';

export default defineConfig({
  plugins: [
    viteImagemin({
      webp: {
        quality: 80,
      },
      optipng: {
        optimizationLevel: 7,
      },
    }),
  ],
});
```

### Image Optimization Checklist

| Check | Action |
|-------|--------|
| Format | Use WebP for photos, SVG for icons |
| Size | Max 100KB for thumbnails, 500KB for heroes |
| Dimensions | Always set width/height |
| Loading | lazy for below-fold, eager for hero |
| Responsive | Use srcSet for multiple sizes |

## Verification

```bash
# Check Lighthouse image audit
npx lighthouse https://www.botjao.com --only-categories=performance

# Check Network tab
# Images should be < 100KB each
# Hero image should load first (eager)
```

## Prevention

- Compress images before commit
- Use WebP format by default
- Set up image optimization in build
- Review image sizes in PR
- Use CDN for user-uploaded images

## Project-Specific Notes

**BotFacebook Context:**
- User avatars: LINE/Telegram CDN URLs
- Handle avatar 404s gracefully with fallback
- Bot icons: Optimize to < 50KB
- Use Cloudflare CDN for caching
