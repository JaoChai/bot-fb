---
id: react-003-virtualization
title: List Virtualization
impact: HIGH
impactDescription: "Rendering large lists causing performance issues"
category: react
tags: [react, virtualization, list, performance]
relatedRules: [react-001-re-renders, react-002-memoization]
---

## Symptom

- Slow scrolling on long lists
- Page freezes when loading many items
- High memory usage
- Initial render takes too long
- Browser tab becomes unresponsive

## Root Cause

1. Rendering all items at once
2. No virtualization for large lists
3. Complex item components
4. Re-rendering entire list on change
5. Large DOM tree

## Diagnosis

### Quick Check

```tsx
// Check item count
console.log('Rendering items:', items.length);
// > 100 items = consider virtualization

// Check DOM node count
document.querySelectorAll('*').length;
// > 5000 nodes = too many
```

### Detailed Analysis

```tsx
// Profile list render time
console.time('list render');
return (
  <ul>
    {items.map(item => <ListItem key={item.id} item={item} />)}
  </ul>
);
console.timeEnd('list render');
// > 16ms = causing frame drops
```

## Measurement

```
Before: 1000 items = 1000 DOM nodes, slow scroll
Target: 1000 items = ~20 DOM nodes, smooth scroll
```

## Solution

### Fix Steps

1. **Use @tanstack/react-virtual**
```tsx
import { useVirtualizer } from '@tanstack/react-virtual';

function VirtualList({ items }: { items: Item[] }) {
  const parentRef = useRef<HTMLDivElement>(null);

  const virtualizer = useVirtualizer({
    count: items.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 50,  // Estimated row height
    overscan: 5,  // Render 5 extra items above/below
  });

  return (
    <div ref={parentRef} className="h-[400px] overflow-auto">
      <div
        style={{
          height: `${virtualizer.getTotalSize()}px`,
          width: '100%',
          position: 'relative',
        }}
      >
        {virtualizer.getVirtualItems().map(virtualRow => (
          <div
            key={virtualRow.key}
            style={{
              position: 'absolute',
              top: 0,
              left: 0,
              width: '100%',
              height: `${virtualRow.size}px`,
              transform: `translateY(${virtualRow.start}px)`,
            }}
          >
            <ListItem item={items[virtualRow.index]} />
          </div>
        ))}
      </div>
    </div>
  );
}
```

2. **Variable height items**
```tsx
const virtualizer = useVirtualizer({
  count: items.length,
  getScrollElement: () => parentRef.current,
  estimateSize: (index) => {
    // Estimate based on content
    return items[index].type === 'expanded' ? 150 : 50;
  },
  measureElement: (element) => {
    // Measure actual height for accuracy
    return element.getBoundingClientRect().height;
  },
});
```

3. **Virtual grid**
```tsx
import { useVirtualizer } from '@tanstack/react-virtual';

function VirtualGrid({ items }: { items: Item[] }) {
  const parentRef = useRef<HTMLDivElement>(null);

  const rowVirtualizer = useVirtualizer({
    count: Math.ceil(items.length / 3),  // 3 columns
    getScrollElement: () => parentRef.current,
    estimateSize: () => 200,
  });

  return (
    <div ref={parentRef} className="h-[600px] overflow-auto">
      <div style={{ height: rowVirtualizer.getTotalSize() }}>
        {rowVirtualizer.getVirtualItems().map(virtualRow => {
          const rowStart = virtualRow.index * 3;
          return (
            <div
              key={virtualRow.key}
              className="grid grid-cols-3 gap-4"
              style={{
                position: 'absolute',
                top: virtualRow.start,
                height: virtualRow.size,
              }}
            >
              {items.slice(rowStart, rowStart + 3).map(item => (
                <GridItem key={item.id} item={item} />
              ))}
            </div>
          );
        })}
      </div>
    </div>
  );
}
```

4. **Windowed chat messages**
```tsx
function ChatMessages({ messages }: { messages: Message[] }) {
  const parentRef = useRef<HTMLDivElement>(null);

  const virtualizer = useVirtualizer({
    count: messages.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 80,
    overscan: 10,
    // Scroll to bottom for chat
    initialOffset: messages.length * 80,
  });

  // Auto-scroll on new message
  useEffect(() => {
    virtualizer.scrollToIndex(messages.length - 1, {
      align: 'end',
      behavior: 'smooth',
    });
  }, [messages.length]);

  return (
    <div ref={parentRef} className="flex-1 overflow-auto">
      <div style={{ height: virtualizer.getTotalSize() }}>
        {virtualizer.getVirtualItems().map(virtualRow => (
          <MessageBubble
            key={virtualRow.key}
            message={messages[virtualRow.index]}
            style={{
              position: 'absolute',
              top: virtualRow.start,
            }}
          />
        ))}
      </div>
    </div>
  );
}
```

5. **Pagination as alternative**
```tsx
// For simpler cases, paginate instead
function PaginatedList({ items }: { items: Item[] }) {
  const [page, setPage] = useState(1);
  const pageSize = 20;

  const paginatedItems = items.slice(
    (page - 1) * pageSize,
    page * pageSize
  );

  return (
    <>
      <ul>
        {paginatedItems.map(item => (
          <ListItem key={item.id} item={item} />
        ))}
      </ul>
      <Pagination
        current={page}
        total={Math.ceil(items.length / pageSize)}
        onChange={setPage}
      />
    </>
  );
}
```

### When to Virtualize

| Item Count | DOM Complexity | Action |
|------------|----------------|--------|
| < 50 | Simple | No virtualization |
| < 100 | Complex | Consider virtualization |
| > 100 | Any | Use virtualization |
| > 500 | Any | Must virtualize |

## Verification

```tsx
// Check DOM node count during scroll
setInterval(() => {
  console.log('DOM nodes:', document.querySelectorAll('*').length);
}, 1000);
// Should stay constant with virtualization

// FPS counter during scroll
// Chrome DevTools > Performance > FPS meter
```

## Prevention

- Plan for large lists early
- Use virtualization for > 100 items
- Test with production data sizes
- Profile scroll performance
- Consider pagination for simpler UX

## Project-Specific Notes

**BotFacebook Context:**
- Chat messages: Use virtualization
- Bot list: Pagination (usually < 50)
- Knowledge base docs: Virtualization
- Conversation list: Virtualization
- Library: @tanstack/react-virtual
