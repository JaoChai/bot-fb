---
id: frontend-002-custom-hooks
title: Extract Custom Hooks
impact: HIGH
impactDescription: "Complex state logic in components reduces reusability and testability"
category: frontend
tags: [react, hooks, refactoring, reusability]
relatedRules: [frontend-001-component-size, frontend-005-hook-deps]
---

## Why This Matters

Custom hooks extract reusable logic from components. Without them, state management logic gets duplicated and components become bloated.

## Bad Example

```tsx
function ConversationPage() {
  // All this logic duplicated in other components
  const [messages, setMessages] = useState<Message[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const [page, setPage] = useState(1);

  useEffect(() => {
    setIsLoading(true);
    fetchMessages(conversationId, page)
      .then(data => {
        setMessages(prev => [...prev, ...data]);
        setIsLoading(false);
      })
      .catch(err => {
        setError(err);
        setIsLoading(false);
      });
  }, [conversationId, page]);

  const loadMore = () => setPage(p => p + 1);

  // Same pattern repeated in other components...
}
```

**Why it's wrong:**
- Logic mixed with UI
- Can't reuse in other components
- Hard to test state logic
- Duplicated across components

## Good Example

```tsx
// Custom hook with all logic
function useMessages(conversationId: number) {
  const [messages, setMessages] = useState<Message[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const [page, setPage] = useState(1);

  useEffect(() => {
    const controller = new AbortController();
    setIsLoading(true);

    fetchMessages(conversationId, page, controller.signal)
      .then(data => setMessages(prev => [...prev, ...data]))
      .catch(err => {
        if (!controller.signal.aborted) setError(err);
      })
      .finally(() => setIsLoading(false));

    return () => controller.abort();
  }, [conversationId, page]);

  return {
    messages,
    isLoading,
    error,
    loadMore: () => setPage(p => p + 1),
    refresh: () => { setPage(1); setMessages([]); }
  };
}

// Clean component
function ConversationPage({ conversationId }: Props) {
  const { messages, isLoading, loadMore } = useMessages(conversationId);

  return (
    <div>
      <MessageList messages={messages} />
      {isLoading && <Spinner />}
      <Button onClick={loadMore}>Load More</Button>
    </div>
  );
}
```

**Why it's better:**
- Logic encapsulated
- Reusable in any component
- Testable without rendering
- Component stays clean

## Review Checklist

- [ ] Complex state extracted to hooks
- [ ] Hooks in `src/hooks/` directory
- [ ] Hook names start with `use`
- [ ] Hooks return object (not array for >2 values)
- [ ] AbortController for fetch cleanup

## Detection

```bash
# Components with many useEffect
grep -c "useEffect" src/components/**/*.tsx | sort -t: -k2 -n | tail -10

# Missing custom hooks
ls src/hooks/ | wc -l

# State logic that should be hooks
grep -A 5 "useState.*\[\]" src/components/**/*.tsx | grep "useEffect"
```

## Project-Specific Notes

**BotFacebook Custom Hooks:**

```
src/hooks/
├── useAuth.ts              # Auth state & actions
├── useBots.ts              # Bot list with React Query
├── useBot.ts               # Single bot
├── useConversations.ts     # Conversation list
├── useMessages.ts          # Message pagination
├── useWebSocket.ts         # Real-time connection
└── useInfiniteScroll.ts    # Scroll loading

# Pattern: React Query for server state
function useBots() {
  return useQuery({
    queryKey: queryKeys.bots.list(),
    queryFn: () => api.bots.list(),
  });
}

# Pattern: Custom state for local state
function useToggle(initial = false) {
  const [value, setValue] = useState(initial);
  const toggle = useCallback(() => setValue(v => !v), []);
  return [value, toggle] as const;
}
```
