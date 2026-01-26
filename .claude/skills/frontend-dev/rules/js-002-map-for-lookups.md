---
id: js-002-map-for-lookups
title: Use Map for Repeated Lookups
impact: LOW-MEDIUM
impactDescription: "O(n) → O(1) lookup - noticeable with 100+ items"
category: js
tags: [javascript, performance, data-structures, optimization]
relatedRules: []
---

## Why This Matters

Array `.find()` is O(n) - it checks each element until it finds a match. For repeated lookups in large arrays, building a Map first (O(n) once) then using Map.get() (O(1) each) is much faster.

## Bad Example

```tsx
// Problem: O(n) lookup repeated many times
function renderConversations(conversations: Conversation[], bots: Bot[]) {
  return conversations.map(conv => {
    // find() is O(n) - runs for EVERY conversation
    const bot = bots.find(b => b.id === conv.botId);
    return <ConversationCard conversation={conv} bot={bot} />;
  });
  // 100 conversations × 50 bots = 5000 comparisons worst case
}

// Problem: Repeated lookups in event handler
function handleSelect(id: string) {
  const item = items.find(i => i.id === id);  // O(n) every click
  if (item) {
    selectItem(item);
  }
}
```

**Why it's wrong:**
- `.find()` scans array from start each time
- Repeated calls = O(n²) total complexity
- Slow with large datasets

## Good Example

```tsx
// Solution: Build Map once, O(1) lookups
function renderConversations(conversations: Conversation[], bots: Bot[]) {
  // Build lookup map once - O(n)
  const botMap = new Map(bots.map(b => [b.id, b]));

  return conversations.map(conv => {
    // Map.get() is O(1)
    const bot = botMap.get(conv.botId);
    return <ConversationCard conversation={conv} bot={bot} />;
  });
  // 100 conversations = 100 O(1) lookups = fast
}

// Solution: Memoize the map
function ConversationList({ conversations, bots }: Props) {
  const botMap = useMemo(
    () => new Map(bots.map(b => [b.id, b])),
    [bots]
  );

  return conversations.map(conv => (
    <ConversationCard
      conversation={conv}
      bot={botMap.get(conv.botId)}
    />
  ));
}
```

**Why it's better:**
- Map built once: O(n)
- Each lookup: O(1)
- Total: O(n) instead of O(n²)

## Object vs Map

```tsx
// Object works for string keys
const botLookup: Record<string, Bot> = {};
bots.forEach(b => { botLookup[b.id] = b; });
const bot = botLookup[conv.botId];

// Map is better when:
// - Keys are not strings (numbers, objects)
// - You need to iterate in insertion order
// - You need .size property
// - You're adding/removing keys frequently

const botMap = new Map<string, Bot>(bots.map(b => [b.id, b]));
const bot = botMap.get(conv.botId);
```

## When to Optimize

```tsx
// Don't optimize for small arrays
// find() is fine for < 50 items

// Optimize when:
// - Array has 100+ items
// - Lookup is called many times
// - Lookup is in render path or hot loop
// - You notice performance issues

// Quick check:
console.time('lookup');
for (let i = 0; i < 1000; i++) {
  items.find(x => x.id === targetId);
}
console.timeEnd('lookup');
```

## Multiple Keys Lookup

```tsx
// Need to look up by multiple fields?
function createLookups(users: User[]) {
  return {
    byId: new Map(users.map(u => [u.id, u])),
    byEmail: new Map(users.map(u => [u.email, u])),
    byUsername: new Map(users.map(u => [u.username, u])),
  };
}

const lookups = useMemo(() => createLookups(users), [users]);
const user = lookups.byEmail.get(email);
```

## Project-Specific Notes

BotFacebook patterns where Map helps:
- Bot lookup by ID in conversation list
- User lookup by ID in message list
- Message lookup by ID for optimistic updates
- Knowledge base item lookup

## References

- [MDN: Map](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Map)
- [Big O Cheat Sheet](https://www.bigocheatsheet.com/)
