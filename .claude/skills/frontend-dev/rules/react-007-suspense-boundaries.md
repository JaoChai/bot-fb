---
id: react-007-suspense-boundaries
title: Suspense Boundaries
impact: HIGH
impactDescription: "Provides smooth loading states for async components and code splitting"
category: react
tags: [suspense, loading, lazy, code-splitting, async]
relatedRules: [react-003-use-hook, react-006-error-boundaries, perf-002-code-splitting]
---

## Why This Matters

Suspense lets you declaratively specify loading states for async components. Without proper Suspense boundaries, lazy-loaded components and components using `use()` will cause errors or show nothing while loading.

Strategic Suspense placement improves perceived performance by showing loading states at the right granularity.

## Bad Example

```tsx
// Problem 1: No Suspense for lazy component
const Dashboard = lazy(() => import('./pages/Dashboard'));

function App() {
  return (
    <Routes>
      <Route path="/dashboard" element={<Dashboard />} />
      {/* Error! Dashboard suspends but no Suspense boundary */}
    </Routes>
  );
}

// Problem 2: Suspense too high - entire page shows loading
function App() {
  return (
    <Suspense fallback={<FullPageLoader />}>
      <Header />
      <Sidebar />
      <MainContent /> {/* Loading any part shows full page loader */}
    </Suspense>
  );
}

// Problem 3: No fallback or poor fallback
function BotList() {
  return (
    <Suspense fallback={<div>Loading...</div>}>
      <BotGrid />
    </Suspense>
  );
}
```

**Why it's wrong:**
- Missing Suspense causes runtime errors
- Single top-level Suspense causes jarring loading experience
- Generic "Loading..." doesn't match content shape (layout shift)
- User loses context of what's loading

## Good Example

```tsx
// Solution: Strategic Suspense placement with skeleton fallbacks
const Dashboard = lazy(() => import('./pages/Dashboard'));
const Settings = lazy(() => import('./pages/Settings'));

function App() {
  return (
    <div className="flex min-h-screen">
      {/* Header loads immediately - no Suspense needed */}
      <Header />

      <div className="flex flex-1">
        {/* Sidebar can have its own loading state */}
        <Suspense fallback={<SidebarSkeleton />}>
          <Sidebar />
        </Suspense>

        {/* Main content has page-level Suspense */}
        <main className="flex-1">
          <Suspense fallback={<PageSkeleton />}>
            <Routes>
              <Route path="/dashboard" element={<Dashboard />} />
              <Route path="/settings" element={<Settings />} />
            </Routes>
          </Suspense>
        </main>
      </div>
    </div>
  );
}

// Skeleton that matches content shape
function BotGridSkeleton() {
  return (
    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="h-32 animate-pulse rounded-lg bg-muted" />
      ))}
    </div>
  );
}

function BotList() {
  return (
    <Suspense fallback={<BotGridSkeleton />}>
      <BotGrid />
    </Suspense>
  );
}

// Nested Suspense for granular loading
function BotDashboard() {
  return (
    <div className="space-y-6">
      <h1>Bot Dashboard</h1>

      {/* Stats load independently */}
      <Suspense fallback={<StatsSkeleton />}>
        <BotStats />
      </Suspense>

      {/* Charts load independently */}
      <Suspense fallback={<ChartSkeleton />}>
        <PerformanceChart />
      </Suspense>

      {/* List loads independently */}
      <Suspense fallback={<BotGridSkeleton />}>
        <BotGrid />
      </Suspense>
    </div>
  );
}

// Combined with Error Boundary
function SafeAsyncComponent({ children }) {
  return (
    <ErrorBoundary fallback={<ErrorFallback />}>
      <Suspense fallback={<LoadingSkeleton />}>
        {children}
      </Suspense>
    </ErrorBoundary>
  );
}
```

**Why it's better:**
- Lazy components always have a Suspense boundary
- Skeletons match content shape (no layout shift)
- Independent loading states for different sections
- Users see partial content while rest loads
- Combined with Error Boundary for complete coverage

## Project-Specific Notes

**BotFacebook Lazy Loading Pattern:**
```tsx
// src/router.tsx
import { lazy, Suspense } from 'react';
import { PageSkeleton } from '@/components/skeletons';

// Lazy with retry for network failures
const lazyWithRetry = (importFn) =>
  lazy(() =>
    importFn().catch(() => {
      // Retry once on failure
      return new Promise((resolve) => {
        setTimeout(() => resolve(importFn()), 1000);
      });
    })
  );

const Dashboard = lazyWithRetry(() => import('./pages/Dashboard'));
```

**Skeleton Components Location:**
- `src/components/skeletons/` - Reusable skeleton components
- Match skeleton dimensions to actual content
- Use `animate-pulse` for loading animation

**When to Add Suspense:**
- Around lazy() imports
- Around components using use() hook
- Around React Query components with suspense: true
- At route boundaries for page transitions

## References

- [React Suspense](https://react.dev/reference/react/Suspense)
- [Code Splitting](https://react.dev/reference/react/lazy)
- Related rule: perf-002-code-splitting
