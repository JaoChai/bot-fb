---
name: frontend-dev
description: |
  React 19 specialist for frontend implementation. Handles components, state management with Zustand, data fetching with React Query v5, styling with Tailwind v4.
  Triggers: 'React', 'component', 'hook', 'frontend', 'UI', 'TypeScript', 'Zustand'.
  Use when: creating/modifying React components, pages, hooks, or fixing UI issues. For design decisions, use /ui-ux-pro-max first.
allowed-tools:
  - Bash(npm run*)
  - Bash(npx*)
  - Read
  - Grep
  - Edit
context:
  - path: src/lib/query.ts
  - path: src/lib/api.ts
  - path: src/router.tsx
  - path: src/stores/
---

# Frontend Development

React 19 + TypeScript specialist for BotFacebook frontend.

## Quick Start

```typescript
// Standard component pattern
import { cn } from '@/lib/utils';

interface Props {
  className?: string;
  children: React.ReactNode;
}

export function Component({ className, children }: Props) {
  return <div className={cn('base-styles', className)}>{children}</div>;
}
```

## Critical Rules (Check First)

| Rule | Impact | Issue |
|------|--------|-------|
| [gotcha-001](rules/gotcha-001-response-data-access.md) | CRITICAL | `response.data.data` access |
| [gotcha-002](rules/gotcha-002-infinite-rerenders.md) | CRITICAL | Infinite re-renders from unstable refs |
| [react-006](rules/react-006-error-boundaries.md) | CRITICAL | Missing error boundaries |
| [query-001](rules/query-001-query-keys-factory.md) | CRITICAL | Inconsistent query keys |
| [perf-004](rules/perf-004-stable-references.md) | CRITICAL | Unstable deps cause loops |

## MCP Tools Available

- **context7**: `resolve-library-id`, `query-docs` - Get latest React/library docs
- **chrome**: `screenshot`, `computer`, `read_page` - UI testing and automation
- **claude-mem**: `search`, `get_observations` - Search past implementations

## Memory Search (Before Starting)

**Always search memory first** to find past component patterns.

```
search(query="React component", project="bot-fb", type="feature", limit=5)
search(query="custom hook", project="bot-fb", concepts=["pattern"], limit=5)
```

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

## Rule Categories

| Category | Rules | Key Topics |
|----------|-------|------------|
| [React](rules/) | react-001 to react-008 | Components, hooks, React 19 features |
| [React Query](rules/) | query-001 to query-007 | Caching, mutations, invalidation |
| [State](rules/) | state-001 to state-003 | Zustand, selectors, prop drilling |
| [Performance](rules/) | perf-001 to perf-005 | Memoization, code splitting, bundle |
| [Accessibility](rules/) | a11y-001 to a11y-004 | Semantic HTML, keyboard, ARIA |
| [Styling](rules/) | style-001 to style-003 | cn(), CVA, Tailwind conflicts |
| [TypeScript](rules/) | ts-001 to ts-003 | Types, unions, API responses |
| [Gotchas](rules/) | gotcha-001 to gotcha-005 | Common mistakes |

**Full rule reference:** See [AGENTS.md](AGENTS.md)

## Key Files

| File | Purpose |
|------|---------|
| `src/lib/query.ts` | Query client + keys factory |
| `src/lib/api.ts` | API client (axios) |
| `src/lib/utils.ts` | cn() utility |
| `src/lib/echo.ts` | WebSocket setup |
| `src/stores/*.ts` | Zustand stores |
| `src/hooks/*.ts` | Custom hooks |
| `src/router.tsx` | Route definitions |
| `src/components/ui/` | Radix-based UI primitives |

## Common Tasks

### Create New Component
1. Check if similar exists in `src/components/`
2. Use Radix primitives from `src/components/ui/`
3. Add TypeScript interface
4. Use CVA for variants ([style-002](rules/style-002-cva-variants.md))
5. Use cn() for class merging ([style-001](rules/style-001-cn-utility.md))

### Add New Query
1. Add key to query factory in `src/lib/query.ts`
2. Create hook in `src/hooks/`
3. Use proper types from `src/types/`
4. Follow [query-001](rules/query-001-query-keys-factory.md) pattern

### Add New Page
1. Create in `src/pages/`
2. Add route in `src/router.tsx`
3. Use lazy loading ([perf-002](rules/perf-002-code-splitting.md))
4. Wrap with Suspense ([react-007](rules/react-007-suspense-boundaries.md))

## Real-time (WebSocket/Echo)

**Hooks Available:**
- `useConversationChannel()` - Single conversation
- `useBotChannel()` - All bot conversations
- `useNotifications()` - User notifications

**Auth setup:** See [gotcha-003](rules/gotcha-003-echo-auth-token.md)

## Detailed Guides

- **React 19 Patterns**: See [REACT_PATTERNS.md](REACT_PATTERNS.md)
- **React Query**: See [QUERY_PATTERNS.md](QUERY_PATTERNS.md)
- **All Rules**: See [AGENTS.md](AGENTS.md)
- **Decision Trees**: See [rules/_sections.md](rules/_sections.md)
