---
id: frontend-004-list-keys
title: Unique Keys for Lists
impact: MEDIUM
impactDescription: "Missing or incorrect keys cause rendering bugs and performance issues"
category: frontend
tags: [react, lists, performance, keys]
relatedRules: [frontend-005-hook-deps]
---

## Why This Matters

React uses keys to track list items. Missing or unstable keys cause incorrect updates, lost state, and performance problems.

## Bad Example

```tsx
// No key
{messages.map(msg => (
  <Message content={msg.content} />
))}

// Index as key (problematic for dynamic lists)
{messages.map((msg, index) => (
  <Message key={index} content={msg.content} />
))}

// Non-unique key
{bots.map(bot => (
  <BotCard key={bot.type} bot={bot} /> // Multiple bots same type!
))}

// Generated key
{items.map(item => (
  <Item key={Math.random()} item={item} /> // New key every render!
))}
```

**Why it's wrong:**
- No key: Console warning, bugs
- Index key: Wrong item updated on add/remove
- Non-unique: Duplicate key error
- Random: Entire list re-renders

## Good Example

```tsx
// Unique, stable ID
{messages.map(msg => (
  <Message key={msg.id} message={msg} />
))}

// Composite key when no single ID
{conversationMessages.map(msg => (
  <Message
    key={`${msg.conversationId}-${msg.id}`}
    message={msg}
  />
))}

// Index OK for static, never-reordered lists
{menuItems.map((item, index) => (
  <MenuItem key={index} label={item.label} />
))}

// Generate stable ID for items without one
const itemsWithIds = useMemo(
  () => items.map((item, i) => ({ ...item, _id: item.name || i })),
  [items]
);
```

**Why it's better:**
- Stable IDs from data
- Unique across list
- React can track correctly
- Optimal re-renders

## Review Checklist

- [ ] All `.map()` returns have `key` prop
- [ ] Keys are unique within list
- [ ] Keys are stable (not random)
- [ ] Index only used for static lists
- [ ] Keys from data IDs when available

## Detection

```bash
# Missing keys (console warnings)
# Run app and check browser console

# Index keys (potential issues)
grep -rn "key={index}\|key={i}" --include="*.tsx" src/

# Random keys (always wrong)
grep -rn "key={Math.random\|key={Date.now\|key={uuid()" --include="*.tsx" src/
```

## Project-Specific Notes

**BotFacebook List Patterns:**

```tsx
// Messages - always have ID
{messages.map(msg => (
  <MessageBubble key={msg.id} message={msg} />
))}

// Bots - use ID
{bots.map(bot => (
  <BotCard key={bot.id} bot={bot} />
))}

// Conversations - use ID
{conversations.map(conv => (
  <ConversationItem
    key={conv.id}
    conversation={conv}
    isActive={conv.id === selectedId}
  />
))}

// Static menu items - index OK
const menuItems = ['Dashboard', 'Settings', 'Profile'];
{menuItems.map((label, i) => (
  <MenuItem key={i} label={label} />
))}

// Grouped items - composite key
{groupedMessages.map(group => (
  <div key={group.date}>
    {group.messages.map(msg => (
      <Message key={`${group.date}-${msg.id}`} message={msg} />
    ))}
  </div>
))}
```
