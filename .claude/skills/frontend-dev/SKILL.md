---
name: frontend-dev
description: React 19 specialist for frontend implementation. Handles components, state management with Zustand, data fetching with React Query v5, styling with Tailwind v4. Use when creating/modifying React components, pages, hooks, or fixing UI issues. For design decisions (styles, colors, fonts), use /ui-ux-pro-max first.
---

# Frontend Development

React 19 + TypeScript specialist for BotFacebook frontend.

## Quick Start

```typescript
// Component pattern
import { cn } from '@/lib/utils';

interface Props {
  className?: string;
  children: React.ReactNode;
}

export function Component({ className, children }: Props) {
  return <div className={cn('base-styles', className)}>{children}</div>;
}
```

## MCP Tools Available

- **context7**: `resolve-library-id`, `query-docs` - Get latest React/library docs
- **chrome**: `screenshot`, `computer`, `read_page` - UI testing and automation

## Tech Stack

| Technology | Version | Purpose |
|-----------|---------|---------|
| React | 19.2.0 | UI Framework |
| React Router | 7.11.0 | Routing |
| React Query | 5.90.12 | Server state |
| Zustand | 4.x | Client state |
| Tailwind CSS | 4.1.18 | Styling |
| TypeScript | 5.9.3 | Type safety |
| Vite | 7.2.4 | Build tool |

## Key Patterns

### State Management

**Zustand Store Pattern:**
```typescript
const useStore = create<Store>()(
  persist(
    (set) => ({
      state: initialValue,
      action: () => set({ state: newValue }),
    }),
    { name: 'store-key' }
  )
)
```

**Available Stores:** `useAuthStore`, `useUIStore`, `useConnectionStore`, `useBotPreferencesStore`

### React Query Pattern

```typescript
// Query Keys Factory
queryKeys.bots.list(params)
queryKeys.bots.detail(id)
queryKeys.conversations.messages(convId)

// Mutation with Optimistic Update
useMutation({
  mutationFn: api.update,
  onMutate: async (newData) => {
    await queryClient.cancelQueries(key)
    const previous = queryClient.getQueryData(key)
    queryClient.setQueryData(key, newData)
    return { previous }
  },
  onError: (err, vars, context) => {
    queryClient.setQueryData(key, context.previous)
  },
  onSettled: () => queryClient.invalidateQueries(key)
})
```

### Styling with CVA

```typescript
const buttonVariants = cva(
  "base-classes",
  {
    variants: {
      variant: { default: "...", destructive: "..." },
      size: { default: "...", sm: "...", lg: "..." }
    },
    defaultVariants: { variant: "default", size: "default" }
  }
)
```

**Always use `cn()` utility** (clsx + tailwind-merge)

## Detailed Guides

- **React Patterns**: See [REACT_PATTERNS.md](REACT_PATTERNS.md)
- **Query Patterns**: See [QUERY_PATTERNS.md](QUERY_PATTERNS.md)
- **UI Design**: Use `/ui-ux-pro-max` skill for design decisions

## Key Files

| File | Purpose |
|------|---------|
| `src/lib/query.ts` | Query client setup |
| `src/lib/api.ts` | API client (axios) |
| `src/lib/echo.ts` | WebSocket setup |
| `src/stores/*.ts` | Global state |
| `src/hooks/*.ts` | Custom hooks |
| `src/router.tsx` | Route definitions |
| `src/components/ui/` | Radix-based UI primitives |

## Common Tasks

### Create New Component
1. Check if similar exists in `src/components/`
2. Use Radix primitives from `src/components/ui/`
3. Add TypeScript interface
4. Use CVA for variants
5. Export from index

### Add New Page
1. Create in `src/pages/`
2. Add route in `src/router.tsx`
3. Use `lazyWithRetryNamed()` for lazy loading
4. Wrap with Suspense

### Add New Query
1. Add key to `src/lib/queryKeys.ts`
2. Create hook in `src/hooks/`
3. Use proper types from `src/types/`

## Real-time (WebSocket/Echo)

**Hooks Available:**
- `useConversationChannel()` - Single conversation
- `useBotChannel()` - All bot conversations
- `useNotifications()` - User notifications
- `useBotPresence()` - Who's viewing

## Use Context7 When

- Unsure about React 19 patterns
- Need latest React Query v5 docs
- Checking Zustand middleware usage
- Verifying Tailwind v4 utilities

## Gotchas

| Problem | Cause | Solution |
|---------|-------|----------|
| `response.data` undefined | API wrapper | Access `response.data.data` for actual data |
| Query not refetching | Stale queryKey | Use queryKeys factory, check key dependencies |
| Infinite re-renders | Object in deps array | Memoize with `useMemo` or extract to constant |
| Zustand state not persisting | Storage key conflict | Use unique `name` in persist middleware |
| Tailwind classes not working | Class conflicts | Use `cn()` utility (tailwind-merge) |
| Echo not connecting | Auth token missing | Check `window.Echo` setup, verify Sanctum token |
| Type error on API response | Missing types | Define interface in `src/types/`, use generics |
| Suspense fallback not showing | Missing boundary | Wrap with `<Suspense fallback={...}>` |
| Form not submitting | Missing `type="submit"` | Add type attribute to button |
| Modal closing unexpectedly | Event bubbling | Add `e.stopPropagation()` to modal content |
