---
id: frontend-002-code-splitting
title: Missing Code Splitting
impact: HIGH
impactDescription: "Loading all code upfront instead of on-demand"
category: frontend
tags: [code-splitting, lazy-loading, react, vite]
relatedRules: [frontend-001-bundle-size, vitals-001-lcp]
---

## Symptom

- Single large JS bundle
- Slow initial load even for simple pages
- All routes loaded at once
- No lazy loading indicators

## Root Cause

1. Static imports everywhere
2. No route-based splitting
3. Heavy components not lazy loaded
4. Vendor code not separated
5. Missing Suspense boundaries

## Diagnosis

### Quick Check

```bash
# Check number of chunks
ls frontend/dist/assets/*.js | wc -l
# Should be > 5 for good splitting

# Check if routes are split
grep -r "lazy(" frontend/src/router.tsx
```

### Detailed Analysis

```typescript
// Check import patterns
// Static (bad for large components)
import HeavyComponent from './HeavyComponent';

// Dynamic (good)
const HeavyComponent = lazy(() => import('./HeavyComponent'));
```

## Measurement

```
Before: 1-2 large chunks (>500KB each)
Target: Many small chunks (<100KB each)
```

## Solution

### Fix Steps

1. **Route-based splitting**
```typescript
// router.tsx
import { lazy, Suspense } from 'react';

// Lazy load all pages
const Dashboard = lazy(() => import('./pages/Dashboard'));
const Settings = lazy(() => import('./pages/Settings'));
const Analytics = lazy(() => import('./pages/Analytics'));

// With retry for failed chunks
const lazyWithRetry = (importFn: () => Promise<any>) =>
  lazy(async () => {
    try {
      return await importFn();
    } catch (error) {
      // Retry once on chunk load failure
      window.location.reload();
      return { default: () => null };
    }
  });

const BotBuilder = lazyWithRetry(() => import('./pages/BotBuilder'));
```

2. **Wrap with Suspense**
```typescript
function App() {
  return (
    <Suspense fallback={<PageSkeleton />}>
      <Routes>
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/settings" element={<Settings />} />
      </Routes>
    </Suspense>
  );
}
```

3. **Component-level splitting**
```typescript
// Lazy load heavy components
const RichTextEditor = lazy(() => import('./components/RichTextEditor'));
const ChartComponent = lazy(() => import('./components/ChartComponent'));

function Dashboard() {
  return (
    <div>
      <Header />
      <Suspense fallback={<ChartSkeleton />}>
        <ChartComponent data={data} />
      </Suspense>
    </div>
  );
}
```

4. **Vite manual chunks**
```typescript
// vite.config.ts
export default defineConfig({
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules')) {
            if (id.includes('react')) return 'vendor-react';
            if (id.includes('@radix-ui')) return 'vendor-ui';
            if (id.includes('recharts')) return 'vendor-charts';
            return 'vendor';
          }
        },
      },
    },
  },
});
```

5. **Preload critical chunks**
```typescript
// Preload on hover
const prefetchComponent = () => {
  import('./pages/Settings');
};

<Link
  to="/settings"
  onMouseEnter={prefetchComponent}
>
  Settings
</Link>
```

## Verification

```bash
# Count chunks after build
ls frontend/dist/assets/*.js | wc -l

# Check chunk sizes
ls -lh frontend/dist/assets/*.js

# Verify lazy loading in browser
# Network tab should show chunks loading on navigation
```

## Prevention

- Default to lazy() for all pages
- Use Suspense at route level
- Lazy load components > 50KB
- Configure manual chunks in Vite
- Test chunk loading in development

## Project-Specific Notes

**BotFacebook Context:**
- All pages in `src/pages/` should use lazy loading
- Use `lazyWithRetryNamed()` helper from `src/lib/lazy.ts`
- Heavy components: RichTextEditor, ChartComponent, MonacoEditor
- Suspense boundary at Router level
