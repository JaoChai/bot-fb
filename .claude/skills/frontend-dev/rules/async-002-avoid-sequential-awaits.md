---
id: async-002-avoid-sequential-awaits
title: Avoid Sequential Awaits in Loops
impact: CRITICAL
impactDescription: "Prevents O(n) latency - 10 items × 100ms = 1s vs 100ms parallel"
category: async
tags: [async, loops, performance, waterfall]
relatedRules: [async-001-parallel-fetching]
---

## Why This Matters

Using `await` inside a loop creates sequential execution where each iteration waits for the previous one. For 10 items with 100ms each, that's 1 second of waiting. Running them in parallel takes only ~100ms.

## Bad Example

```tsx
// Problem: Sequential await in loop
async function fetchAllBots(botIds: string[]) {
  const bots = [];

  for (const id of botIds) {
    const bot = await api.getBot(id);  // Each waits for previous!
    bots.push(bot);
  }

  return bots;
  // 10 bots × 100ms = 1000ms total
}
```

**Why it's wrong:**
- Loop iteration waits for previous `await` to complete
- Time grows linearly with array size
- Users wait unnecessarily long

## Good Example

```tsx
// Solution: Promise.all with map
async function fetchAllBots(botIds: string[]) {
  const bots = await Promise.all(
    botIds.map(id => api.getBot(id))
  );

  return bots;
  // 10 bots = ~100ms total (parallel)
}
```

**Why it's better:**
- All requests start simultaneously
- Time = single longest request
- 10x faster for 10 items

## With Error Handling

```tsx
// Promise.allSettled for partial success
async function fetchAllBots(botIds: string[]) {
  const results = await Promise.allSettled(
    botIds.map(id => api.getBot(id))
  );

  const bots = results
    .filter((r): r is PromiseFulfilledResult<Bot> => r.status === 'fulfilled')
    .map(r => r.value);

  const errors = results
    .filter((r): r is PromiseRejectedResult => r.status === 'rejected')
    .map(r => r.reason);

  if (errors.length > 0) {
    console.error('Some bots failed to load:', errors);
  }

  return bots;
}
```

## With Rate Limiting (if needed)

```tsx
// Batch parallel requests to avoid overwhelming server
async function fetchAllBotsInBatches(botIds: string[], batchSize = 5) {
  const results: Bot[] = [];

  for (let i = 0; i < botIds.length; i += batchSize) {
    const batch = botIds.slice(i, i + batchSize);
    const batchResults = await Promise.all(
      batch.map(id => api.getBot(id))
    );
    results.push(...batchResults);
  }

  return results;
}
```

## Project-Specific Notes

Common patterns in BotFacebook:
- Loading multiple bot details in bot list page
- Fetching messages for multiple conversations
- Bulk operations on knowledge base items

## References

- [Promise.allSettled() - MDN](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Promise/allSettled)
- Related rule: [async-001-parallel-fetching](async-001-parallel-fetching.md)
