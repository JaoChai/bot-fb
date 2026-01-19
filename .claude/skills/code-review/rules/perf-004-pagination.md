---
id: perf-004-pagination
title: Use Pagination for Lists
impact: MEDIUM
impactDescription: "Loading all records at once causes memory issues and slow responses"
category: perf
tags: [performance, pagination, api, memory]
relatedRules: [perf-003-over-fetching]
---

## Why This Matters

Loading thousands of records into memory crashes servers and times out requests. Pagination ensures consistent performance regardless of data size.

## Bad Example

```php
// Loading everything
public function index()
{
    return Bot::all(); // 10,000 bots = crash
}

// Even worse with relationships
public function conversations()
{
    return Conversation::with('messages')->get();
    // Could be millions of messages
}

// Frontend loading all at once
const { data } = useQuery({
    queryKey: ['bots'],
    queryFn: () => api.bots.list(), // Returns 10,000 bots
});
```

**Why it's wrong:**
- Memory exhaustion
- Slow responses
- Database timeout
- Poor UX

## Good Example

```php
// Simple pagination
public function index()
{
    return BotResource::collection(
        auth()->user()->bots()->paginate(15)
    );
}

// Cursor pagination for real-time data
public function messages(Conversation $conversation)
{
    return MessageResource::collection(
        $conversation->messages()
            ->latest()
            ->cursorPaginate(50)
    );
}

// Frontend with React Query
const {
    data,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage
} = useInfiniteQuery({
    queryKey: ['bots'],
    queryFn: ({ pageParam = 1 }) => api.bots.list({ page: pageParam }),
    getNextPageParam: (lastPage) => lastPage.meta.current_page < lastPage.meta.last_page
        ? lastPage.meta.current_page + 1
        : undefined,
});
```

**Why it's better:**
- Constant memory usage
- Fast responses
- Scales to any size
- Good UX with infinite scroll

## Review Checklist

- [ ] All list endpoints paginated
- [ ] Default page size reasonable (15-50)
- [ ] Cursor pagination for real-time/large data
- [ ] Frontend uses infinite query/pagination
- [ ] Total count optional (expensive for large tables)

## Detection

```bash
# Unpaginated list endpoints
grep -rn "::all()\|->get()" --include="*.php" app/Http/Controllers/Api/

# Check for paginate usage
grep -rn "paginate\|cursorPaginate" --include="*.php" app/Http/Controllers/
```

## Project-Specific Notes

**BotFacebook Pagination Strategy:**

```php
// Standard pagination for bots (order stable)
public function index()
{
    return BotResource::collection(
        auth()->user()->bots()
            ->with('latestConversation')
            ->withCount('conversations')
            ->latest()
            ->paginate(15)
    );
}

// Cursor pagination for messages (real-time, high volume)
public function messages(Conversation $conversation)
{
    return MessageResource::collection(
        $conversation->messages()
            ->with('toolCalls')
            ->latest()
            ->cursorPaginate(50)
    );
}

// Response format
{
    "data": [...],
    "links": {
        "first": "?page=1",
        "last": "?page=10",
        "prev": null,
        "next": "?page=2"
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 10,
        "per_page": 15,
        "to": 15,
        "total": 150
    }
}

// Frontend infinite scroll
function MessageList({ conversationId }) {
    const { data, fetchNextPage, hasNextPage } = useInfiniteMessages(conversationId);

    return (
        <div ref={containerRef}>
            {data?.pages.flatMap(page => page.data).map(msg => (
                <Message key={msg.id} message={msg} />
            ))}
            {hasNextPage && (
                <IntersectionTrigger onIntersect={fetchNextPage} />
            )}
        </div>
    );
}
```
