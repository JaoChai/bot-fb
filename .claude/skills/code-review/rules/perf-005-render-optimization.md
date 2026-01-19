---
id: perf-005-render-optimization
title: Frontend Render Optimization
impact: MEDIUM
impactDescription: "Unnecessary re-renders cause janky UI and wasted resources"
category: perf
tags: [performance, react, render, optimization]
relatedRules: [frontend-006-memoization, frontend-005-hook-deps]
---

## Why This Matters

React re-renders components when state or props change. Unnecessary re-renders waste CPU cycles, cause UI jank, and drain mobile batteries.

## Bad Example

```tsx
// Expensive calculation in render
function BotList({ bots }: Props) {
    const sorted = bots.sort((a, b) => /* complex sort */); // Every render!

    return sorted.map(bot => <BotCard key={bot.id} bot={bot} />);
}

// Creating objects in render
function Parent() {
    return <Child style={{ color: 'red' }} />; // New object each render
}

// Anonymous functions in render
function List({ items, onSelect }) {
    return items.map(item => (
        <Item
            key={item.id}
            onClick={() => onSelect(item.id)} // New function each render
        />
    ));
}

// State updates causing cascading renders
function Dashboard() {
    const [filter, setFilter] = useState('');

    // Every keystroke re-renders everything
    return (
        <input value={filter} onChange={e => setFilter(e.target.value)} />
        <ExpensiveList filter={filter} />
    );
}
```

**Why it's wrong:**
- Expensive work repeated
- New references trigger re-renders
- Child components re-render unnecessarily
- UI feels sluggish

## Good Example

```tsx
// Memoize expensive calculations
function BotList({ bots }: Props) {
    const sorted = useMemo(
        () => [...bots].sort((a, b) => /* complex sort */),
        [bots]
    );

    return sorted.map(bot => <BotCard key={bot.id} bot={bot} />);
}

// Memoize objects and callbacks
function Parent() {
    const style = useMemo(() => ({ color: 'red' }), []);
    return <Child style={style} />;
}

function List({ items, onSelect }) {
    const handleClick = useCallback((id: number) => {
        onSelect(id);
    }, [onSelect]);

    return items.map(item => (
        <MemoizedItem key={item.id} item={item} onClick={handleClick} />
    ));
}

// Debounce state updates
function Dashboard() {
    const [filter, setFilter] = useState('');
    const debouncedFilter = useDebouncedValue(filter, 300);

    return (
        <>
            <input value={filter} onChange={e => setFilter(e.target.value)} />
            <ExpensiveList filter={debouncedFilter} />
        </>
    );
}

// React.memo for expensive components
const BotCard = memo(function BotCard({ bot }: Props) {
    return <div>{/* expensive render */}</div>;
});
```

**Why it's better:**
- Calculations cached
- Stable references
- Minimal re-renders
- Smooth UI

## Review Checklist

- [ ] Expensive calculations use `useMemo`
- [ ] Callbacks use `useCallback` when passed to children
- [ ] `React.memo` on list item components
- [ ] No inline objects/functions in JSX
- [ ] Debounce rapid state updates

## Detection

```tsx
// React DevTools Profiler
// 1. Open React DevTools
// 2. Go to Profiler tab
// 3. Record while interacting
// 4. Look for components re-rendering unnecessarily

// Console logging
useEffect(() => {
    console.log('Component rendered');
});
```

## Project-Specific Notes

**BotFacebook Render Optimization:**

```tsx
// Message list with virtualization for large lists
import { Virtuoso } from 'react-virtuoso';

function MessageList({ conversationId }: Props) {
    const { data: messages } = useMessages(conversationId);

    return (
        <Virtuoso
            data={messages}
            itemContent={(index, message) => (
                <MemoizedMessage key={message.id} message={message} />
            )}
        />
    );
}

// Memoized list item
const MemoizedMessage = memo(function Message({ message }: MessageProps) {
    return (
        <div className={cn('message', message.role)}>
            {message.content}
        </div>
    );
});

// Debounced search
function BotSearch() {
    const [query, setQuery] = useState('');
    const debouncedQuery = useDebouncedValue(query, 300);

    const { data } = useQuery({
        queryKey: ['bots', 'search', debouncedQuery],
        queryFn: () => api.bots.search(debouncedQuery),
        enabled: debouncedQuery.length > 2,
    });

    return (
        <Input
            value={query}
            onChange={e => setQuery(e.target.value)}
            placeholder="Search bots..."
        />
    );
}

// Transition for non-urgent updates
function FilteredBots() {
    const [filter, setFilter] = useState('all');
    const [isPending, startTransition] = useTransition();

    const handleFilterChange = (newFilter: string) => {
        startTransition(() => {
            setFilter(newFilter); // Non-urgent, won't block UI
        });
    };

    return (
        <div>
            <FilterButtons onChange={handleFilterChange} />
            {isPending ? <Spinner /> : <BotList filter={filter} />}
        </div>
    );
}
```
