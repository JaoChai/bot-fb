---
id: query-007-error-handling
title: Error Handling in Queries
impact: HIGH
impactDescription: "Provides graceful degradation and clear error feedback"
category: query
tags: [react-query, errors, error-handling, ux]
relatedRules: [react-006-error-boundaries, gotcha-001-response-data-access]
---

## Why This Matters

API requests fail - networks are unreliable, servers have errors, tokens expire. Proper error handling prevents blank screens, informs users what went wrong, and allows recovery actions.

React Query provides multiple levels of error handling: component-level, boundary-level, and global-level.

## Bad Example

```tsx
// Problem 1: Ignoring errors
function BotList() {
  const { data } = useQuery({
    queryKey: ['bots'],
    queryFn: fetchBots,
  });

  return <List items={data} />; // Crashes if data is undefined due to error
}

// Problem 2: Generic error messages
function BotDetail({ id }) {
  const { data, error, isError } = useQuery({
    queryKey: ['bots', id],
    queryFn: () => fetchBot(id),
  });

  if (isError) {
    return <div>An error occurred</div>; // Not helpful!
  }

  return <BotView bot={data} />;
}

// Problem 3: No retry for transient errors
const { data } = useQuery({
  queryKey: ['data'],
  queryFn: fetchData,
  retry: 0, // Fails immediately on first error
});

// Problem 4: Same handling for all error types
const { error } = useQuery({
  queryKey: ['protected'],
  queryFn: fetchProtectedData,
});

if (error) {
  return <div>{error.message}</div>; // 401 should redirect, not show error
}
```

**Why it's wrong:**
- Ignoring errors causes runtime crashes
- "An error occurred" doesn't help users
- Network glitches shouldn't fail immediately
- Different error types need different handling

## Good Example

```tsx
// Solution 1: Component-level error handling
function BotList() {
  const { data, error, isError, refetch, isLoading } = useQuery({
    queryKey: queryKeys.bots.list(),
    queryFn: fetchBots,
  });

  if (isLoading) return <BotListSkeleton />;

  if (isError) {
    return (
      <div className="rounded-lg border border-destructive/50 bg-destructive/10 p-4">
        <h3 className="font-semibold text-destructive">Failed to load bots</h3>
        <p className="mt-1 text-sm text-muted-foreground">
          {getErrorMessage(error)}
        </p>
        <button
          onClick={() => refetch()}
          className="mt-3 rounded bg-primary px-3 py-1 text-sm text-white"
        >
          Try Again
        </button>
      </div>
    );
  }

  return <BotGrid bots={data} />;
}

// Solution 2: Error type detection
function getErrorMessage(error: Error): string {
  if (error instanceof AxiosError) {
    switch (error.response?.status) {
      case 400:
        return error.response.data?.message || 'Invalid request';
      case 401:
        return 'Please log in to continue';
      case 403:
        return 'You don\'t have permission to access this';
      case 404:
        return 'The requested resource was not found';
      case 422:
        return error.response.data?.message || 'Validation error';
      case 429:
        return 'Too many requests. Please wait a moment.';
      case 500:
        return 'Server error. Our team has been notified.';
      default:
        return 'Something went wrong. Please try again.';
    }
  }

  if (error.message.includes('Network Error')) {
    return 'Unable to connect. Check your internet connection.';
  }

  return 'An unexpected error occurred';
}

// Solution 3: Global error handling with QueryClient
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (failureCount, error) => {
        // Don't retry on auth errors
        if (error instanceof AxiosError) {
          if ([401, 403, 404].includes(error.response?.status || 0)) {
            return false;
          }
        }
        // Retry up to 3 times for other errors
        return failureCount < 3;
      },
      retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000),
    },
    mutations: {
      onError: (error) => {
        // Global toast for mutation errors
        toast.error(getErrorMessage(error));
      },
    },
  },
});

// Solution 4: Auth error handling with redirect
function useAuthenticatedQuery<T>(
  queryKey: QueryKey,
  queryFn: () => Promise<T>,
  options?: UseQueryOptions<T>
) {
  const navigate = useNavigate();
  const { logout } = useAuthStore();

  return useQuery({
    queryKey,
    queryFn,
    ...options,
    throwOnError: (error) => {
      if (error instanceof AxiosError && error.response?.status === 401) {
        logout();
        navigate('/login', { state: { from: location.pathname } });
        return false; // Don't throw, we're handling it
      }
      return true; // Throw other errors to boundary
    },
  });
}

// Solution 5: Error boundary integration
function BotPage() {
  return (
    <ErrorBoundary
      fallback={({ error, resetErrorBoundary }) => (
        <div className="p-8 text-center">
          <h2 className="text-xl font-bold text-destructive">Error Loading Page</h2>
          <p className="mt-2">{getErrorMessage(error)}</p>
          <button onClick={resetErrorBoundary} className="mt-4 btn">
            Retry
          </button>
        </div>
      )}
      onReset={() => {
        queryClient.invalidateQueries({ queryKey: queryKeys.bots.all });
      }}
    >
      <Suspense fallback={<PageSkeleton />}>
        <BotDetail />
      </Suspense>
    </ErrorBoundary>
  );
}

// Solution 6: Mutation error handling
function useUpdateBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: updateBot,
    onError: (error) => {
      if (error instanceof AxiosError && error.response?.status === 422) {
        // Validation error - return to show in form
        return;
      }
      toast.error(getErrorMessage(error));
    },
    onSuccess: () => {
      toast.success('Bot updated successfully');
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.all });
    },
  });
}
```

**Why it's better:**
- Clear error messages help users understand what happened
- Retry logic handles transient failures
- Auth errors redirect appropriately
- Error boundaries catch unhandled errors
- Users can retry failed requests

## Project-Specific Notes

**BotFacebook Error Handling:**

| Error Code | Handling |
|------------|----------|
| 401 | Redirect to login, clear auth |
| 403 | Show permission error |
| 404 | Show "not found" state |
| 422 | Show validation errors inline |
| 429 | Show rate limit message |
| 500+ | Show server error, report to Sentry |

**Error Toast Utility:**
```tsx
// src/lib/errors.ts
export function handleApiError(error: unknown): void {
  const message = getErrorMessage(error);
  toast.error(message);

  if (shouldReportToSentry(error)) {
    Sentry.captureException(error);
  }
}
```

## References

- [TanStack Query Error Handling](https://tanstack.com/query/latest/docs/framework/react/guides/query-retries)
- [React Error Boundaries](https://react.dev/reference/react/Component#catching-rendering-errors-with-an-error-boundary)
