---
id: frontend-001-bundle-size
title: Large Bundle Size
impact: HIGH
impactDescription: "Slow initial page load due to large JavaScript bundles"
category: frontend
tags: [bundle, webpack, vite, optimization]
relatedRules: [frontend-002-code-splitting, vitals-001-lcp]
---

## Symptom

- Slow initial page load
- Large JS files in network tab (>500KB)
- High Time to Interactive (TTI)
- Poor Lighthouse score

## Root Cause

1. No tree shaking
2. Including entire libraries
3. No code splitting
4. Development dependencies in production
5. Large images/assets in JS bundle

## Diagnosis

### Quick Check

```bash
# Build with analysis
cd frontend && npm run build -- --analyze

# Check bundle size
ls -lh frontend/dist/assets/*.js

# Vite bundle analyzer
npm i -D rollup-plugin-visualizer
```

### Detailed Analysis

```typescript
// vite.config.ts - Add visualizer
import { visualizer } from 'rollup-plugin-visualizer';

export default defineConfig({
  plugins: [
    visualizer({
      filename: 'dist/stats.html',
      open: true,
      gzipSize: true,
    }),
  ],
});
```

## Measurement

```
Before: Bundle > 500KB gzipped
Target: Bundle < 200KB gzipped (main chunk)
```

## Solution

### Fix Steps

1. **Analyze current bundle**
```bash
npm run build
# Check dist/stats.html
```

2. **Import only what you need**
```typescript
// Before (imports entire library)
import _ from 'lodash';
_.debounce(fn, 300);

// After (tree-shakable)
import debounce from 'lodash/debounce';
debounce(fn, 300);

// Or use lodash-es
import { debounce } from 'lodash-es';
```

3. **Lazy load routes**
```typescript
// router.tsx
const Dashboard = lazy(() => import('./pages/Dashboard'));
const Settings = lazy(() => import('./pages/Settings'));
```

4. **Externalize large dependencies**
```typescript
// vite.config.ts
export default defineConfig({
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['react', 'react-dom'],
          ui: ['@radix-ui/react-dialog', '@radix-ui/react-dropdown-menu'],
          charts: ['recharts'],
        },
      },
    },
  },
});
```

5. **Use dynamic imports for heavy components**
```typescript
const HeavyEditor = lazy(() => import('./components/HeavyEditor'));

function Page() {
  return (
    <Suspense fallback={<Loading />}>
      {showEditor && <HeavyEditor />}
    </Suspense>
  );
}
```

## Verification

```bash
# Check final bundle size
npm run build
ls -lh dist/assets/*.js

# Run Lighthouse
npx lighthouse https://www.botjao.com --view

# Check gzipped size
gzip -c dist/assets/index-*.js | wc -c
```

## Prevention

- Run bundle analysis in CI
- Set bundle size budget
- Review new dependency sizes
- Use `bundlephobia.com` before adding packages
- Prefer tree-shakable ESM packages

## Project-Specific Notes

**BotFacebook Context:**
- Target: < 200KB main bundle (gzipped)
- Heavy libs: recharts, monaco-editor (lazy load)
- UI lib: shadcn/ui (tree-shakable)
- Build tool: Vite 7.x
