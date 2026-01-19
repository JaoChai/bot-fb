---
id: perf-003-virtualization
title: Virtualization for Long Lists
impact: MEDIUM
impactDescription: "Enables smooth scrolling of lists with 1000+ items"
category: perf
tags: [virtualization, lists, performance, tanstack-virtual]
relatedRules: [query-005-infinite-queries]
---

## Why This Matters

Rendering thousands of DOM nodes causes jank, slow scrolling, and high memory usage. Virtualization renders only visible items plus a small buffer, keeping DOM size constant regardless of list length.

This is essential for message lists, logs, and large data tables.

## Bad Example

```tsx
// Problem 1: Rendering all items
function MessageList({ messages }: { messages: Message[] }) {
  // 10,000 messages = 10,000 DOM nodes = frozen browser
  return (
    <div className="overflow-auto h-96">
      {messages.map((msg) => (
        <MessageItem key={msg.id} message={msg} />
      ))}
    </div>
  );
}

// Problem 2: Pagination instead of virtualization
function ConversationList({ conversations }) {
  const [page, setPage] = useState(1);
  const pageSize = 50;
  const displayed = conversations.slice(0, page * pageSize);

  return (
    <div>
      {displayed.map(c => <ConvoCard key={c.id} conversation={c} />)}
      <button onClick={() => setPage(p => p + 1)}>Load More</button>
    </div>
  );
  // After 10 "Load More" clicks: 500 DOM nodes
  // Scrolling gets progressively worse
}

// Problem 3: Virtual list with variable heights not handled
function ChatHistory({ messages }) {
  return (
    <VirtualList
      itemCount={messages.length}
      itemSize={50} // Fixed height - but messages vary!
    >
      {({ index, style }) => (
        <div style={style}>
          <Message message={messages[index]} />
        </div>
      )}
    </VirtualList>
  );
  // Messages get cut off or overlap
}
```

**Why it's wrong:**
- Rendering all items overwhelms the DOM
- Pagination accumulates DOM nodes over time
- Fixed heights cause visual bugs with variable content
- Memory grows unbounded

## Good Example

```tsx
// Solution 1: TanStack Virtual for variable height lists
import { useVirtualizer } from '@tanstack/react-virtual';

function MessageList({ messages }: { messages: Message[] }) {
  const parentRef = useRef<HTMLDivElement>(null);

  const virtualizer = useVirtualizer({
    count: messages.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 80, // Estimated average height
    overscan: 5, // Render 5 items above/below viewport
  });

  return (
    <div ref={parentRef} className="h-96 overflow-auto">
      <div
        style={{
          height: `${virtualizer.getTotalSize()}px`,
          width: '100%',
          position: 'relative',
        }}
      >
        {virtualizer.getVirtualItems().map((virtualItem) => (
          <div
            key={virtualItem.key}
            style={{
              position: 'absolute',
              top: 0,
              left: 0,
              width: '100%',
              transform: `translateY(${virtualItem.start}px)`,
            }}
          >
            <MessageItem
              message={messages[virtualItem.index]}
              measureRef={virtualizer.measureElement}
            />
          </div>
        ))}
      </div>
    </div>
  );
}

// Solution 2: Dynamic measurement for variable heights
function MessageItem({
  message,
  measureRef,
}: {
  message: Message;
  measureRef: (el: HTMLElement | null) => void;
}) {
  return (
    <div ref={measureRef} className="border-b p-4">
      <span className="font-semibold">{message.sender}</span>
      <p className="mt-1 whitespace-pre-wrap">{message.content}</p>
      {message.attachments?.map((att) => (
        <Attachment key={att.id} attachment={att} />
      ))}
    </div>
  );
}

// Solution 3: Virtualized table with fixed columns
function DataTable({ data }: { data: Row[] }) {
  const parentRef = useRef<HTMLDivElement>(null);

  const rowVirtualizer = useVirtualizer({
    count: data.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 48, // Row height
    overscan: 10,
  });

  return (
    <div ref={parentRef} className="h-[600px] overflow-auto">
      <table className="w-full">
        <thead className="sticky top-0 bg-background">
          <tr>
            <th>Name</th>
            <th>Status</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody
          style={{
            height: `${rowVirtualizer.getTotalSize()}px`,
            position: 'relative',
          }}
        >
          {rowVirtualizer.getVirtualItems().map((virtualRow) => (
            <tr
              key={virtualRow.key}
              style={{
                position: 'absolute',
                top: `${virtualRow.start}px`,
                height: '48px',
                width: '100%',
              }}
            >
              <td>{data[virtualRow.index].name}</td>
              <td>{data[virtualRow.index].status}</td>
              <td>{data[virtualRow.index].createdAt}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// Solution 4: Infinite scroll + virtualization
function InfiniteMessageList({ conversationId }: { conversationId: string }) {
  const parentRef = useRef<HTMLDivElement>(null);

  const {
    data,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
  } = useInfiniteQuery({
    queryKey: queryKeys.conversations.messages(conversationId),
    queryFn: ({ pageParam }) => fetchMessages(conversationId, pageParam),
    initialPageParam: 1,
    getNextPageParam: (lastPage) => lastPage.nextCursor,
  });

  const allMessages = data?.pages.flatMap((p) => p.data) ?? [];

  const virtualizer = useVirtualizer({
    count: hasNextPage ? allMessages.length + 1 : allMessages.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 80,
    overscan: 5,
  });

  // Load more when reaching bottom
  useEffect(() => {
    const lastItem = virtualizer.getVirtualItems().at(-1);
    if (
      lastItem &&
      lastItem.index >= allMessages.length - 1 &&
      hasNextPage &&
      !isFetchingNextPage
    ) {
      fetchNextPage();
    }
  }, [virtualizer.getVirtualItems(), hasNextPage, fetchNextPage, isFetchingNextPage, allMessages.length]);

  return (
    <div ref={parentRef} className="h-[500px] overflow-auto">
      <div style={{ height: `${virtualizer.getTotalSize()}px`, position: 'relative' }}>
        {virtualizer.getVirtualItems().map((virtualItem) => (
          <div
            key={virtualItem.key}
            style={{
              position: 'absolute',
              top: 0,
              left: 0,
              width: '100%',
              transform: `translateY(${virtualItem.start}px)`,
            }}
          >
            {virtualItem.index < allMessages.length ? (
              <MessageItem message={allMessages[virtualItem.index]} />
            ) : (
              <LoadingItem />
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
```

**Why it's better:**
- Only visible items are rendered (~20 vs thousands)
- Constant DOM size regardless of list length
- Dynamic measurement handles variable heights
- Combines with infinite queries for large datasets
- Smooth 60fps scrolling

## Project-Specific Notes

**When to Virtualize in BotFacebook:**

| List | Virtualize? | Reason |
|------|-------------|--------|
| Messages in conversation | Yes | Can be 10,000+ |
| Conversation list | Maybe | If >100 conversations |
| Bot list | No | Usually <50 |
| Activity logs | Yes | Thousands of entries |
| Search results | Maybe | Depends on result count |

**TanStack Virtual Installation:**
```bash
npm install @tanstack/react-virtual
```

**Performance Targets:**
- DOM nodes: <200 (regardless of list size)
- Scroll FPS: 60
- Initial render: <100ms

## References

- [TanStack Virtual](https://tanstack.com/virtual/latest)
- [Virtualization Explained](https://web.dev/virtualize-long-lists-react-window/)
