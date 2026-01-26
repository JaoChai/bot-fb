---
id: perf-008-content-visibility
title: Use content-visibility for Long Lists
impact: MEDIUM
impactDescription: "Skips rendering off-screen content - improves initial render by 50%+"
category: perf
tags: [css, rendering, performance, lists]
relatedRules: [perf-003-virtualization]
---

## Why This Matters

`content-visibility: auto` tells the browser to skip rendering elements that are off-screen. Unlike virtualization libraries, it's pure CSS and works with any content. Great for long lists, comment sections, or content-heavy pages.

## Bad Example

```tsx
// Problem: All items render immediately
function ConversationList({ conversations }: Props) {
  return (
    <div className="h-screen overflow-y-auto">
      {conversations.map(conv => (
        <ConversationCard key={conv.id} conversation={conv} />
      ))}
    </div>
  );
  // 100 conversations = 100 items rendered, even if only 10 visible
}
```

**Why it's wrong:**
- Browser renders all items on initial load
- Wasted computation for off-screen content
- Slow initial paint for long lists

## Good Example

```tsx
// Solution: content-visibility on list items
function ConversationList({ conversations }: Props) {
  return (
    <div className="h-screen overflow-y-auto">
      {conversations.map(conv => (
        <div
          key={conv.id}
          className="content-visibility-auto contain-intrinsic-size-[auto_80px]"
        >
          <ConversationCard conversation={conv} />
        </div>
      ))}
    </div>
  );
  // Browser skips rendering off-screen items
}
```

**Why it's better:**
- Off-screen items skip rendering entirely
- Much faster initial paint
- Browser handles visibility automatically

## Tailwind CSS Setup

```css
/* Add to your CSS or tailwind config */
@layer utilities {
  .content-visibility-auto {
    content-visibility: auto;
  }

  .contain-intrinsic-size-auto-80 {
    contain-intrinsic-size: auto 80px;
  }
}
```

```tsx
// Usage
<div className="content-visibility-auto contain-intrinsic-size-auto-80">
  <ExpensiveComponent />
</div>
```

## Important: contain-intrinsic-size

```tsx
// Must set intrinsic size for smooth scrolling
// Without it, scrollbar jumps as items render

// Fixed height items
<div style={{
  contentVisibility: 'auto',
  containIntrinsicSize: '0 80px'  // 80px tall
}}>

// Variable height - use 'auto' keyword
<div style={{
  contentVisibility: 'auto',
  containIntrinsicSize: 'auto 100px'  // Remember actual size after render
}}>
```

## When to Use What

```
Need to render list?
├── < 50 items
│   └── Regular rendering (no optimization needed)
│
├── 50-500 items
│   └── content-visibility: auto (CSS solution)
│
├── > 500 items OR complex items
│   └── Virtualization (react-virtual, react-window)
│
└── Infinite scroll
    └── Virtualization + infinite query
```

## Combining with Virtualization

```tsx
// For very long lists, use both
// Virtualization for DOM management
// content-visibility for render optimization within visible range

import { useVirtualizer } from '@tanstack/react-virtual';

function VirtualizedList({ items }: Props) {
  const virtualizer = useVirtualizer({
    count: items.length,
    estimateSize: () => 80,
    overscan: 5,
  });

  return (
    <div ref={parentRef}>
      {virtualizer.getVirtualItems().map(virtualRow => (
        <div
          key={virtualRow.key}
          className="content-visibility-auto"
          style={{ height: virtualRow.size }}
        >
          <ItemCard item={items[virtualRow.index]} />
        </div>
      ))}
    </div>
  );
}
```

## Project-Specific Notes

Good candidates in BotFacebook:
- Conversation list (sidebar)
- Message history (can be 100s of messages)
- Knowledge base item list
- Bot list for users with many bots

## References

- [MDN: content-visibility](https://developer.mozilla.org/en-US/docs/Web/CSS/content-visibility)
- [web.dev: content-visibility](https://web.dev/content-visibility/)
- Related rule: [perf-003-virtualization](perf-003-virtualization.md)
