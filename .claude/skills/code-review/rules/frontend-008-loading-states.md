---
id: frontend-008-loading-states
title: Handle Loading and Error States
impact: MEDIUM
impactDescription: "Missing states cause blank screens and poor user experience"
category: frontend
tags: [react, loading, error, ux, state-management]
relatedRules: [frontend-002-custom-hooks]
---

## Why This Matters

Every async operation has loading and error states. Not handling them causes blank screens, confusing UX, or uncaught errors shown to users.

## Bad Example

```tsx
// No loading or error handling
function BotList() {
  const [bots, setBots] = useState([]);

  useEffect(() => {
    api.bots.list().then(data => setBots(data));
    // What if loading? What if error?
  }, []);

  return (
    <ul>
      {bots.map(bot => <li key={bot.id}>{bot.name}</li>)}
    </ul>
  ); // Empty list while loading - confusing!
}

// Partial handling
function BotDetail({ id }) {
  const [bot, setBot] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.bots.get(id).then(data => {
      setBot(data);
      setLoading(false);
    });
    // Error silently ignored!
  }, [id]);

  if (loading) return <Spinner />;
  return <div>{bot.name}</div>; // Crashes if bot is null after error
}
```

**Why it's wrong:**
- Users see blank/empty state
- Errors silently swallowed
- No way to retry
- Poor UX

## Good Example

```tsx
// Manual state handling
function BotList() {
  const [bots, setBots] = useState<Bot[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  useEffect(() => {
    setIsLoading(true);
    setError(null);

    api.bots.list()
      .then(data => setBots(data))
      .catch(err => setError(err))
      .finally(() => setIsLoading(false));
  }, []);

  if (isLoading) return <BotListSkeleton />;
  if (error) return <ErrorState error={error} onRetry={() => window.location.reload()} />;
  if (bots.length === 0) return <EmptyState message="No bots yet" />;

  return (
    <ul>
      {bots.map(bot => <li key={bot.id}>{bot.name}</li>)}
    </ul>
  );
}

// Better: React Query handles everything
function BotList() {
  const { data: bots, isLoading, error, refetch } = useQuery({
    queryKey: ['bots'],
    queryFn: () => api.bots.list(),
  });

  if (isLoading) return <BotListSkeleton />;
  if (error) return <ErrorState error={error} onRetry={refetch} />;
  if (!bots?.length) return <EmptyState message="No bots yet" />;

  return (
    <ul>
      {bots.map(bot => <li key={bot.id}>{bot.name}</li>)}
    </ul>
  );
}
```

**Why it's better:**
- Clear loading indicator
- Error shown with retry
- Empty state handled
- Good UX

## Review Checklist

- [ ] Loading state shown during fetch
- [ ] Error state with retry option
- [ ] Empty state for no data
- [ ] Skeleton loaders for better UX
- [ ] Consider React Query for simplicity

## Detection

```bash
# Async without loading state
grep -B 5 ".then(" --include="*.tsx" src/ | grep -v "isLoading\|loading\|setLoading"

# Missing error handling
grep -A 5 "useEffect" --include="*.tsx" src/ | grep "fetch\|api\." | grep -v "catch"
```

## Project-Specific Notes

**BotFacebook Loading Patterns:**

```tsx
// Standard pattern with React Query
function ConversationList({ botId }: Props) {
  const {
    data: conversations,
    isLoading,
    error,
    refetch
  } = useConversations(botId);

  if (isLoading) {
    return <ConversationListSkeleton count={5} />;
  }

  if (error) {
    return (
      <Alert variant="destructive">
        <AlertTitle>Failed to load conversations</AlertTitle>
        <AlertDescription>
          {error.message}
          <Button variant="link" onClick={() => refetch()}>
            Try again
          </Button>
        </AlertDescription>
      </Alert>
    );
  }

  if (!conversations?.length) {
    return (
      <EmptyState
        icon={<MessageSquare className="w-12 h-12" />}
        title="No conversations yet"
        description="Start chatting to see conversations here"
      />
    );
  }

  return (
    <ul className="divide-y">
      {conversations.map(conv => (
        <ConversationItem key={conv.id} conversation={conv} />
      ))}
    </ul>
  );
}

// Suspense for cleaner loading
function BotDashboard() {
  return (
    <Suspense fallback={<DashboardSkeleton />}>
      <ErrorBoundary fallback={<DashboardError />}>
        <BotDashboardContent />
      </ErrorBoundary>
    </Suspense>
  );
}
```
