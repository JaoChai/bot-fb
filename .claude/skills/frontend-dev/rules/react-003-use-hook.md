---
id: react-003-use-hook
title: use() Hook for Promises (React 19)
impact: HIGH
impactDescription: "Enables cleaner async data handling with Suspense integration"
category: react
tags: [react-19, hooks, async, suspense, promises]
relatedRules: [react-007-suspense-boundaries, query-002-enabled-option]
---

## Why This Matters

React 19's `use()` hook allows reading promises and context directly in render. It integrates with Suspense to show loading states without explicit loading state management.

This simplifies async component code but requires proper Suspense boundaries to handle the suspended state.

## Bad Example

```tsx
// Problem 1: Manual loading state management
function UserProfile({ userId }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  useEffect(() => {
    setLoading(true);
    fetchUser(userId)
      .then(setUser)
      .catch(setError)
      .finally(() => setLoading(false));
  }, [userId]);

  if (loading) return <Spinner />;
  if (error) return <Error message={error.message} />;
  if (!user) return null;

  return <div>{user.name}</div>;
}

// Problem 2: Using use() without Suspense boundary
function App() {
  return (
    <div>
      <UserProfile userPromise={fetchUser(1)} />
      {/* No Suspense! App crashes when promise suspends */}
    </div>
  );
}
```

**Why it's wrong:**
- Manual loading/error state is verbose and error-prone
- Easy to forget edge cases (race conditions, stale closures)
- Without Suspense boundary, suspended components crash the app
- Each async component duplicates the same pattern

## Good Example

```tsx
// Solution: use() with Suspense
'use client';

import { use, Suspense } from 'react';

interface UserProfileProps {
  userPromise: Promise<User>;
}

function UserProfile({ userPromise }: UserProfileProps) {
  const user = use(userPromise); // Suspends until resolved
  return (
    <div className="p-4">
      <h1 className="text-xl font-bold">{user.name}</h1>
      <p className="text-muted-foreground">{user.email}</p>
    </div>
  );
}

// Parent with Suspense boundary
function App() {
  const userPromise = fetchUser(1); // Create promise once

  return (
    <div>
      <h1>User Dashboard</h1>
      <Suspense fallback={<UserProfileSkeleton />}>
        <UserProfile userPromise={userPromise} />
      </Suspense>
    </div>
  );
}

// Can also use with context
function ThemeButton() {
  const theme = use(ThemeContext); // Read context with use()
  return <button className={theme.buttonClass}>Click me</button>;
}

// Conditional use() is allowed (unlike other hooks)
function OptionalData({ dataPromise, showData }) {
  if (!showData) {
    return <p>Data hidden</p>;
  }

  const data = use(dataPromise); // OK! use() can be conditional
  return <DataDisplay data={data} />;
}
```

**Why it's better:**
- No manual loading state management
- Suspense handles loading UI declaratively
- Cleaner component code
- `use()` can be called conditionally (unlike useState/useEffect)

## Project-Specific Notes

**When to use use() vs React Query:**

| Scenario | Recommendation |
|----------|----------------|
| Simple one-time fetch | `use()` with Suspense |
| Cache, retry, refetch | React Query |
| Complex state management | React Query |
| Server Component data | `use()` or direct await |

**BotFacebook Pattern:**
```tsx
// For most cases, prefer React Query
const { data: user } = useQuery({
  queryKey: ['users', userId],
  queryFn: () => api.get(`/users/${userId}`),
});

// For simple, non-cached data
function UserTooltip({ userPromise }) {
  const user = use(userPromise);
  return <span>{user.name}</span>;
}
```

**Error Handling with use():**
```tsx
// Error boundaries catch promise rejections
<ErrorBoundary fallback={<ErrorMessage />}>
  <Suspense fallback={<Loading />}>
    <UserProfile userPromise={fetchUser(id)} />
  </Suspense>
</ErrorBoundary>
```

## References

- [React use() Hook](https://react.dev/reference/react/use)
- [Suspense for Data Fetching](https://react.dev/reference/react/Suspense)
- Related rule: react-007-suspense-boundaries
