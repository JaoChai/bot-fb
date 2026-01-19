---
id: react-006-error-boundaries
title: Error Boundaries
impact: CRITICAL
impactDescription: "Prevents entire app crashes from uncaught component errors"
category: react
tags: [error-handling, boundaries, resilience, sentry]
relatedRules: [query-007-error-handling, react-007-suspense-boundaries]
---

## Why This Matters

Without error boundaries, a JavaScript error in one component can crash your entire React app. Error boundaries catch errors during rendering, in lifecycle methods, and in constructors of child components.

They act as a safety net, allowing you to show fallback UI instead of a blank screen.

## Bad Example

```tsx
// Problem: No error boundary - app crashes on error
function App() {
  return (
    <div>
      <Header />
      <Dashboard /> {/* If this throws, entire app crashes */}
      <Footer />
    </div>
  );
}

// Problem: Only one boundary at the top - everything fails together
function App() {
  return (
    <ErrorBoundary>
      <Header />
      <Sidebar />
      <MainContent /> {/* Error here breaks sidebar and header too */}
    </ErrorBoundary>
  );
}

// Problem: Try-catch doesn't work in render
function BotCard({ bot }) {
  try {
    return <div>{bot.name.toUpperCase()}</div>; // Error if bot.name is null
  } catch (e) {
    return <div>Error</div>; // This catch never runs!
  }
}
```

**Why it's wrong:**
- Uncaught errors cause white screen of death
- Single top-level boundary means any error breaks everything
- try-catch doesn't catch render errors (only event handlers)
- Users lose all context and work when app crashes

## Good Example

```tsx
// Solution: Error boundary component
import { Component, ErrorInfo, ReactNode } from 'react';
import * as Sentry from '@sentry/react';

interface Props {
  children: ReactNode;
  fallback?: ReactNode;
  onError?: (error: Error, errorInfo: ErrorInfo) => void;
}

interface State {
  hasError: boolean;
  error: Error | null;
}

class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false, error: null };

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    // Log to Sentry
    Sentry.captureException(error, {
      extra: { componentStack: errorInfo.componentStack },
    });

    // Custom error handler
    this.props.onError?.(error, errorInfo);
  }

  render() {
    if (this.state.hasError) {
      return this.props.fallback || <DefaultErrorFallback error={this.state.error} />;
    }

    return this.props.children;
  }
}

// Default fallback UI
function DefaultErrorFallback({ error }: { error: Error | null }) {
  return (
    <div className="flex flex-col items-center justify-center p-8 text-center">
      <h2 className="text-lg font-semibold text-destructive">
        Something went wrong
      </h2>
      <p className="mt-2 text-sm text-muted-foreground">
        {error?.message || 'An unexpected error occurred'}
      </p>
      <button
        onClick={() => window.location.reload()}
        className="mt-4 rounded bg-primary px-4 py-2 text-white"
      >
        Reload Page
      </button>
    </div>
  );
}

// Strategic placement - isolate failure domains
function App() {
  return (
    <ErrorBoundary fallback={<AppCrashFallback />}>
      <Header /> {/* If header fails, show minimal fallback */}

      <div className="flex">
        <ErrorBoundary fallback={<SidebarFallback />}>
          <Sidebar />
        </ErrorBoundary>

        <ErrorBoundary fallback={<ContentFallback />}>
          <MainContent /> {/* Error here doesn't affect sidebar */}
        </ErrorBoundary>
      </div>

      <Footer />
    </ErrorBoundary>
  );
}

// Feature-level boundaries
function BotDashboard() {
  return (
    <div className="grid gap-4">
      <ErrorBoundary fallback={<StatsFallback />}>
        <BotStats />
      </ErrorBoundary>

      <ErrorBoundary fallback={<ConversationsFallback />}>
        <RecentConversations />
      </ErrorBoundary>
    </div>
  );
}
```

**Why it's better:**
- App stays functional when one component fails
- Users can continue using unaffected features
- Errors are logged to Sentry with component stack
- Granular fallbacks maintain UI context
- Recovery option (reload button)

## Project-Specific Notes

**BotFacebook Error Boundary Locations:**
- `src/components/error-boundary.tsx` - Base error boundary
- Wrap each major feature section
- Wrap route components in router

**Integration with Sentry:**
```tsx
// Use Sentry's error boundary for automatic capture
import { ErrorBoundary } from '@sentry/react';

<ErrorBoundary
  fallback={<ErrorFallback />}
  showDialog // Shows feedback dialog to users
>
  <App />
</ErrorBoundary>
```

**What Error Boundaries Don't Catch:**
- Event handlers (use try-catch)
- Async code (use try-catch or query error handling)
- Server-side rendering
- Errors in the boundary itself

## References

- [React Error Boundaries](https://react.dev/reference/react/Component#catching-rendering-errors-with-an-error-boundary)
- [Sentry React SDK](https://docs.sentry.io/platforms/javascript/guides/react/)
