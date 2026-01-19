---
id: perf-002-code-splitting
title: Code Splitting with lazy()
impact: MEDIUM
impactDescription: "Reduces initial bundle size for faster page loads"
category: perf
tags: [lazy, suspense, bundle, code-splitting, performance]
relatedRules: [react-007-suspense-boundaries, perf-005-bundle-size]
---

## Why This Matters

Code splitting loads JavaScript on demand instead of all at once. For a large app, this can reduce initial load from megabytes to kilobytes, making the first paint much faster.

`React.lazy()` enables component-level code splitting with dynamic imports.

## Bad Example

```tsx
// Problem 1: All pages imported at top level
import Dashboard from './pages/Dashboard';
import Settings from './pages/Settings';
import Analytics from './pages/Analytics';
import BotEditor from './pages/BotEditor';
import ConversationView from './pages/ConversationView';
// All pages loaded upfront, even if user never visits them!

function App() {
  return (
    <Routes>
      <Route path="/dashboard" element={<Dashboard />} />
      <Route path="/settings" element={<Settings />} />
      <Route path="/analytics" element={<Analytics />} />
      {/* ... */}
    </Routes>
  );
}

// Problem 2: lazy without Suspense
const Dashboard = lazy(() => import('./pages/Dashboard'));

function App() {
  return <Dashboard />; // Error! No Suspense boundary
}

// Problem 3: Splitting too granularly
// Every tiny component lazy loaded - overhead exceeds benefit
const Button = lazy(() => import('./components/Button'));
const Input = lazy(() => import('./components/Input'));
const Label = lazy(() => import('./components/Label'));
```

**Why it's wrong:**
- Static imports bundle everything together
- Missing Suspense causes runtime errors
- Over-splitting small components adds network overhead
- Initial bundle includes code user may never need

## Good Example

```tsx
// Solution 1: Lazy load route components
import { lazy, Suspense } from 'react';
import { PageSkeleton } from '@/components/skeletons';

// Heavy pages loaded on demand
const Dashboard = lazy(() => import('./pages/Dashboard'));
const Settings = lazy(() => import('./pages/Settings'));
const Analytics = lazy(() => import('./pages/Analytics'));
const BotEditor = lazy(() => import('./pages/BotEditor'));

// Light/common pages can stay eager
import Login from './pages/Login';
import NotFound from './pages/NotFound';

function App() {
  return (
    <Suspense fallback={<PageSkeleton />}>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/settings" element={<Settings />} />
        <Route path="/analytics" element={<Analytics />} />
        <Route path="/bots/:id/edit" element={<BotEditor />} />
        <Route path="*" element={<NotFound />} />
      </Routes>
    </Suspense>
  );
}

// Solution 2: Lazy with retry on failure
function lazyWithRetry<T extends React.ComponentType<any>>(
  importFn: () => Promise<{ default: T }>
) {
  return lazy(() =>
    importFn().catch((error) => {
      // Wait and retry once on network failure
      return new Promise<{ default: T }>((resolve, reject) => {
        setTimeout(() => {
          importFn().then(resolve).catch(reject);
        }, 1000);
      });
    })
  );
}

const Dashboard = lazyWithRetry(() => import('./pages/Dashboard'));

// Solution 3: Named exports with lazy
// If component is named export, not default:
const BotSettings = lazy(() =>
  import('./pages/BotSettings').then((module) => ({
    default: module.BotSettings,
  }))
);

// Helper for cleaner syntax
function lazyNamed<T extends React.ComponentType<any>>(
  importFn: () => Promise<Record<string, T>>,
  name: string
) {
  return lazy(() =>
    importFn().then((module) => ({ default: module[name] as T }))
  );
}

const BotAnalytics = lazyNamed(
  () => import('./pages/bot'),
  'BotAnalytics'
);

// Solution 4: Preload on hover/intent
const DashboardLoader = () => import('./pages/Dashboard');
const Dashboard = lazy(DashboardLoader);

function SidebarLink() {
  return (
    <Link
      to="/dashboard"
      onMouseEnter={() => DashboardLoader()} // Preload on hover
    >
      Dashboard
    </Link>
  );
}

// Solution 5: Split heavy dependencies
// For pages with heavy deps (charts, editors)
const ChartPage = lazy(() =>
  import('./pages/Analytics').then(async (module) => {
    // Ensure chart library is loaded
    await import('chart.js');
    return module;
  })
);

// Solution 6: Route-based splitting with granular Suspense
function App() {
  return (
    <Layout>
      <Suspense fallback={<SidebarSkeleton />}>
        <Sidebar />
      </Suspense>

      <main>
        <Suspense fallback={<PageSkeleton />}>
          <Routes>
            <Route path="/dashboard" element={<Dashboard />} />
            <Route path="/bots/:id/*" element={
              <Suspense fallback={<BotDetailSkeleton />}>
                <BotRoutes />
              </Suspense>
            } />
          </Routes>
        </Suspense>
      </main>
    </Layout>
  );
}
```

**Why it's better:**
- Pages load only when navigated to
- Retry handles flaky networks
- Named export support
- Preloading anticipates user navigation
- Granular Suspense for better UX

## Project-Specific Notes

**BotFacebook Code Splitting Strategy:**

| Category | Lazy Load? | Reason |
|----------|------------|--------|
| Route pages | Yes | User visits one at a time |
| Modals | Maybe | If heavy, lazy load |
| UI primitives | No | Small, used everywhere |
| Auth pages | No | Critical path |
| Dashboard | Yes | Heavy charts/data |

**Vite Build Analysis:**
```bash
# Analyze bundle
npm run build -- --report

# Check chunk sizes
ls -la dist/assets/*.js | sort -k5 -n
```

**Target Chunk Sizes:**
- Initial bundle: < 200KB (gzipped)
- Per-route chunks: < 100KB
- Vendor chunks: Split by library

## References

- [React lazy](https://react.dev/reference/react/lazy)
- [Vite Code Splitting](https://vitejs.dev/guide/features.html#async-chunk-loading-optimization)
