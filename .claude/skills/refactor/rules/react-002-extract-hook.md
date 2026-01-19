---
id: react-002-extract-hook
title: Extract Custom Hook Refactoring
impact: HIGH
impactDescription: "Extract reusable logic into custom hooks"
category: react
tags: [extract, hook, react, reuse]
relatedRules: [react-001-extract-component, react-003-extract-utility]
---

## Code Smell

- Same useState/useEffect pattern repeated
- Complex state logic in component
- Hook logic mixed with UI logic
- Hard to test component logic
- Logic duplicated across components

## Root Cause

1. Started as simple component
2. Logic grew over time
3. Copy-pasted between components
4. No custom hook pattern established
5. Unfamiliar with custom hooks

## When to Apply

**Apply when:**
- Same hooks pattern in 2+ components
- State logic > 20 lines
- Logic is independent of UI
- Need to test logic separately

**Don't apply when:**
- Logic is truly component-specific
- Would create unnecessary indirection
- Single use case

## Solution

### Before

```tsx
// BotList.tsx
function BotList() {
  const [bots, setBots] = useState<Bot[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [sortBy, setSortBy] = useState<'name' | 'created'>('created');

  useEffect(() => {
    setIsLoading(true);
    api.bots.list()
      .then(data => setBots(data))
      .catch(err => setError(err))
      .finally(() => setIsLoading(false));
  }, []);

  const filteredBots = useMemo(() => {
    return bots
      .filter(bot => bot.name.toLowerCase().includes(searchQuery.toLowerCase()))
      .sort((a, b) => {
        if (sortBy === 'name') return a.name.localeCompare(b.name);
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      });
  }, [bots, searchQuery, sortBy]);

  // Same pattern repeated in ConversationList, UserList, etc.
}

// ConversationList.tsx - SAME PATTERN
function ConversationList() {
  const [conversations, setConversations] = useState<Conversation[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  // ... same logic
}
```

### After

```tsx
// hooks/useFilteredList.ts
interface UseFilteredListOptions<T> {
  initialSortBy?: keyof T;
  searchFields?: (keyof T)[];
}

function useFilteredList<T extends Record<string, any>>(
  items: T[],
  options: UseFilteredListOptions<T> = {}
) {
  const { initialSortBy, searchFields = [] } = options;

  const [searchQuery, setSearchQuery] = useState('');
  const [sortBy, setSortBy] = useState<keyof T | undefined>(initialSortBy);
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc');

  const filteredItems = useMemo(() => {
    let result = [...items];

    // Filter by search query
    if (searchQuery && searchFields.length > 0) {
      const query = searchQuery.toLowerCase();
      result = result.filter(item =>
        searchFields.some(field =>
          String(item[field]).toLowerCase().includes(query)
        )
      );
    }

    // Sort
    if (sortBy) {
      result.sort((a, b) => {
        const aVal = a[sortBy];
        const bVal = b[sortBy];
        const comparison = aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
        return sortDirection === 'asc' ? comparison : -comparison;
      });
    }

    return result;
  }, [items, searchQuery, searchFields, sortBy, sortDirection]);

  return {
    filteredItems,
    searchQuery,
    setSearchQuery,
    sortBy,
    setSortBy,
    sortDirection,
    setSortDirection,
    toggleSort: (field: keyof T) => {
      if (sortBy === field) {
        setSortDirection(d => d === 'asc' ? 'desc' : 'asc');
      } else {
        setSortBy(field);
        setSortDirection('desc');
      }
    },
  };
}

// hooks/useBots.ts
function useBots() {
  return useQuery({
    queryKey: queryKeys.bots.list(),
    queryFn: api.bots.list,
  });
}

function useFilteredBots() {
  const { data: bots = [], isLoading, error } = useBots();

  const {
    filteredItems,
    searchQuery,
    setSearchQuery,
    sortBy,
    toggleSort,
  } = useFilteredList(bots, {
    initialSortBy: 'created_at',
    searchFields: ['name', 'description'],
  });

  return {
    bots: filteredItems,
    isLoading,
    error,
    searchQuery,
    setSearchQuery,
    sortBy,
    toggleSort,
  };
}

// BotList.tsx - CLEAN
function BotList() {
  const {
    bots,
    isLoading,
    error,
    searchQuery,
    setSearchQuery,
    sortBy,
    toggleSort,
  } = useFilteredBots();

  if (isLoading) return <ListSkeleton />;
  if (error) return <ErrorMessage error={error} />;

  return (
    <div>
      <SearchInput
        value={searchQuery}
        onChange={setSearchQuery}
        placeholder="Search bots..."
      />
      <SortButtons
        sortBy={sortBy}
        onSort={toggleSort}
        fields={['name', 'created_at']}
      />
      <BotGrid bots={bots} />
    </div>
  );
}
```

### Step-by-Step

1. **Identify repeated patterns**
   ```bash
   grep -rn "useState" src/components/ | wc -l
   # Find components with similar patterns
   ```

2. **Create hook file**
   ```bash
   touch src/hooks/useFilteredList.ts
   ```

3. **Extract logic**
   - Move state declarations
   - Move effects
   - Move memoized values
   - Define return type

4. **Add TypeScript generics**
   - Make hook reusable
   - Add proper types

5. **Replace in components**
   - Import hook
   - Replace state/effects
   - Use returned values

## Verification

```bash
# Type check
npm run type-check

# Test hook
npm run test -- useFilteredList

# Verify components still work
npm run test -- BotList
```

## Anti-Patterns

- **Over-abstraction**: Don't extract single-use logic
- **Leaky abstraction**: Hook should be self-contained
- **Side effects**: Be careful with useEffect in hooks
- **Breaking rules of hooks**: Follow hook rules

## Project-Specific Notes

**BotFacebook Context:**
- Hooks location: `src/hooks/`
- Naming: use{Feature} (useBots, useAuth)
- Query hooks use React Query
- State hooks use Zustand when global
