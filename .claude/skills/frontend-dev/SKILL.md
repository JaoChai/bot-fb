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
| [async-001](rules/async-001-parallel-fetching.md) | CRITICAL | Waterfall requests - use Promise.all |
| [async-002](rules/async-002-avoid-sequential-awaits.md) | CRITICAL | Sequential awaits in loops |
| [bundle-001](rules/bundle-001-barrel-imports.md) | CRITICAL | Barrel imports prevent tree-shaking |
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
| [Async](rules/) | async-001 to async-003 | Parallel fetching, waterfalls, preloading |
| [Bundle](rules/) | bundle-001 to bundle-002 | Tree-shaking, barrel imports |
| [React](rules/) | react-001 to react-008 | Components, hooks, React 19 features |
| [React Query](rules/) | query-001 to query-007 | Caching, mutations, invalidation |
| [State](rules/) | state-001 to state-003 | Zustand, selectors, prop drilling |
| [Performance](rules/) | perf-001 to perf-008 | Memoization, code splitting, content-visibility |
| [JavaScript](rules/) | js-001 to js-003 | Layout thrashing, Map lookups, immutability |
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
| `src/hooks/chat/` | 8 specialized chat hooks (useConversationDetails, useConversationList, useMessageMutations, useRealtime, useTags, etc.) |
| `src/types/api.ts` | TypeScript API interfaces - most frequently changed file, critical for TS-Laravel sync |
| `src/types/realtime.ts` | WebSocket/realtime event types |
| `src/types/quick-reply.ts` | Quick reply types |
| `src/lib/stream.ts` | Streaming utilities |
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

## Tailwind v4 Note

This project uses **Tailwind v4** - configuration is via CSS (`src/index.css` with `@theme` directive), NOT via `tailwind.config.ts`. There is no `tailwind.config.ts` file.

## Stale Closure Warning (useRealtime)

`src/hooks/chat/useRealtime.ts` has a known stale closure pattern: recursive `setTimeout` inside `useEffect` captures state at effect creation time. Fix with `useRef` to keep current values accessible in async callbacks (see project MEMORY.md).

## Real-time (WebSocket/Echo)

**Hooks Available:**
- `useConversationChannel()` - Single conversation
- `useBotChannel()` - All bot conversations
- `useNotifications()` - User notifications

**Auth setup:** See [gotcha-003](rules/gotcha-003-echo-auth-token.md)

## Dashboard Architecture

Single-scrollable page with 7 sections, responsive grid layout.

### Sections (top to bottom)

| # | Section | Components | Grid |
|---|---------|-----------|------|
| 1 | StatCards | 4 metric cards | 2-col mobile, 4-col desktop |
| 2 | RevenueChart + BotStatus | Chart + status panel | 3-col layout |
| 3 | Products | Product breakdown | Full width |
| 4 | Cost | Cost analytics | Full width |
| 5 | Orders | Order summary | Full width |
| 6 | Activity | Recent activity | Full width |
| 7 | VIP Stats | Crown icon + customer count + total spent | In RevenueChart |

### Key Components (11 total)

All in `frontend/src/components/dashboard/`:
- `StatCard`, `StatCards` — Key metric display
- `RevenueChart` — Revenue over time + VIP stats
- `BotStatusPanel` — Bot health indicators
- `ProductBreakdown` — Product analytics
- `CostAnalytics` — Cost tracking
- `OrderSummary` — Order statistics
- `ActivityFeed` — Recent conversation activity

### Data Hooks

| Hook | Purpose |
|------|---------|
| `useDashboardSummary` | Main dashboard metrics |
| `useCostAnalytics` | Cost breakdown data |
| `useOrderSummary` | Order statistics |

## Detailed Guides

- **React 19 Patterns**: See [REACT_PATTERNS.md](REACT_PATTERNS.md)
- **React Query**: See [QUERY_PATTERNS.md](QUERY_PATTERNS.md)
- **All Rules**: See [AGENTS.md](AGENTS.md)
- **Decision Trees**: See [rules/_sections.md](rules/_sections.md)
