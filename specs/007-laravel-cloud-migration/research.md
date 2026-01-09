# Research: Laravel Cloud Migration with Inertia.js

**Feature Branch**: `007-laravel-cloud-migration`
**Date**: 2026-01-09
**Status**: Complete

## Research Topics

### 1. Inertia.js + React Integration

**Decision**: Use Inertia.js v2 with React 19 adapter

**Rationale**:
- Official support from Laravel team (Laravel Breeze/Jetstream use Inertia)
- Maintains React components while eliminating need for separate API
- SSR support available for SEO if needed
- Built-in form handling with `useForm` hook
- Automatic CSRF protection

**Key Patterns**:

```tsx
// Page component with props from Laravel controller
import { Head, useForm } from '@inertiajs/react'

export default function Dashboard({ bots, stats }: PageProps) {
    return (
        <>
            <Head title="Dashboard" />
            <div>{/* Use bots and stats directly from props */}</div>
        </>
    )
}
```

**Alternatives Considered**:
- Livewire: Rejected - requires PHP-based components, not compatible with existing React
- API-only with React Query: Current state - adds complexity of separate frontend

---

### 2. TanStack Query Migration Strategy

**Decision**: Replace TanStack Query with Inertia patterns

**Rationale**:
- Inertia automatically provides fresh data on page visits
- `useForm` hook handles mutations with optimistic updates
- `router.reload()` for partial reloads
- Reduces 199 query/mutation operations to simpler patterns

**Migration Patterns**:

| Current (TanStack Query) | Inertia Equivalent |
|--------------------------|-------------------|
| `useQuery(['bots'])` | Props from controller |
| `useMutation(createBot)` | `useForm().post('/bots')` |
| `useInfiniteQuery` | `router.get()` with merge |
| `queryClient.invalidate()` | `router.reload({ only: ['bots'] })` |

**Exceptions (Keep Client-Side)**:
- Real-time message updates (Echo handles)
- SSE streaming responses (not Inertia)
- Optimistic UI for chat typing indicators

---

### 3. Echo/Reverb WebSocket with Inertia

**Decision**: Keep Echo client-side, update data via Inertia partial reloads

**Rationale**:
- Echo works independently of Inertia
- WebSocket events trigger `router.reload({ only: ['conversations'] })`
- No conflict with Inertia's page model

**Implementation Pattern**:

```tsx
// In Inertia page component
import { router } from '@inertiajs/react'
import Echo from 'laravel-echo'

useEffect(() => {
    const channel = window.Echo.private(`bot.${botId}`)

    channel.listen('MessageReceived', (e) => {
        // Trigger partial reload for fresh data
        router.reload({ only: ['conversations', 'messages'] })
    })

    return () => channel.stopListening('MessageReceived')
}, [botId])
```

**Alternatives Considered**:
- Inertia polling: Rejected - inefficient for real-time
- Full SPA with API: Current state - more complex

---

### 4. SSE Streaming with Inertia

**Decision**: Keep SSE endpoints as API routes, not Inertia pages

**Rationale**:
- SSE requires streaming response, incompatible with Inertia JSON
- Flow test streaming stays as `/api/flows/{id}/test` endpoint
- React component calls API directly for streaming

**Implementation**:

```php
// routes/api.php - NOT web.php (Inertia)
Route::post('/flows/{flow}/test', [FlowController::class, 'test'])
    ->middleware('auth:sanctum'); // Session auth works
```

```tsx
// React component in Inertia page
const testFlow = async () => {
    const response = await fetch(`/api/flows/${flowId}/test`, {
        method: 'POST',
        credentials: 'include', // Session cookie
    })

    const reader = response.body.getReader()
    // Handle SSE stream
}
```

---

### 5. Authentication Migration

**Decision**: Session-based auth with Inertia middleware

**Rationale**:
- Laravel's native session auth is simpler than JWT/Sanctum tokens
- Inertia handles CSRF automatically
- No token storage in localStorage (security improvement)

**Implementation**:

```php
// HandleInertiaRequests.php
public function share(Request $request): array
{
    return array_merge(parent::share($request), [
        'auth' => [
            'user' => fn () => $request->user()?->only('id', 'name', 'email'),
        ],
        'flash' => [
            'success' => fn () => $request->session()->get('success'),
            'error' => fn () => $request->session()->get('error'),
        ],
    ]);
}
```

```tsx
// Access in any React component
import { usePage } from '@inertiajs/react'

const { auth, flash } = usePage().props
```

---

### 6. Zustand Store Migration

**Decision**: Migrate to Inertia shared data + minimal local state

**Stores to Migrate**:

| Current Store | Inertia Equivalent |
|---------------|-------------------|
| `authStore` | `usePage().props.auth` (shared data) |
| `chatStore` | URL params + local `useState` |
| `uiStore` | Local `useState` or `localStorage` |
| `botPreferencesStore` | URL params or server-side user preferences |

**Keep as Client State**:
- Sidebar toggle state
- Modal open/close
- Form dirty state (handled by `useForm`)

---

### 7. Infinite Scroll (Conversations/Messages)

**Decision**: Use Inertia's `merge` option with custom pagination

**Rationale**:
- Inertia supports merging data for infinite scroll
- `router.get()` with `preserveState` and `only` options

**Implementation**:

```tsx
const loadMore = () => {
    router.get(
        route('chat.index'),
        { cursor: lastCursor },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['conversations'],
            onSuccess: (page) => {
                // Merge with existing conversations
                setConversations(prev => [...prev, ...page.props.conversations.data])
            }
        }
    )
}
```

---

### 8. Laravel Cloud Serverless Postgres + pgvector

**Decision**: Use Laravel Cloud Serverless Postgres (Neon backend)

**Rationale**:
- Confirmed pgvector support (Laravel Cloud uses Neon)
- Auto-scaling, pay-per-use
- No migration of pgvector extension needed

**Migration Steps**:
1. Create Serverless Postgres in Laravel Cloud
2. Export data from current Neon database
3. Import to Laravel Cloud Postgres
4. Update `DATABASE_URL` environment variable

**Verification**:
```sql
-- Verify pgvector after migration
SELECT * FROM pg_extension WHERE extname = 'vector';
```

---

### 9. Vite Configuration for Inertia + React

**Decision**: Use Laravel Vite Plugin with React and Inertia plugins

**Configuration**:

```typescript
// vite.config.ts
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
})
```

---

### 10. Component Migration Strategy

**Decision**: Preserve component structure, adapt imports

**Migration Order** (by complexity):
1. UI components (Radix) - Copy as-is
2. Layout components - Adapt to Inertia layouts
3. Simple pages (Dashboard, Settings) - Convert to Inertia pages
4. Form-heavy pages (Bots, KB) - Use `useForm`
5. Real-time pages (Chat) - Keep Echo hooks
6. Streaming pages (Flow Editor) - Keep SSE pattern

**File Mapping**:

```
frontend/src/components/ui/     → resources/js/Components/ui/
frontend/src/pages/            → resources/js/Pages/
frontend/src/hooks/useEcho.ts  → resources/js/Hooks/useEcho.ts
frontend/src/lib/echo.ts       → resources/js/Lib/echo.ts
```

---

## Summary

All research topics resolved. No blockers identified.

| Topic | Decision | Risk |
|-------|----------|------|
| Inertia.js | v2 + React adapter | Low |
| TanStack Query | Replace with Inertia patterns | Medium |
| Echo/Reverb | Keep, use partial reloads | Low |
| SSE Streaming | Keep as API routes | Low |
| Auth | Session-based | Low |
| Zustand | Migrate to shared data | Low |
| Infinite Scroll | Use merge option | Medium |
| Database | Laravel Cloud Postgres + pgvector | Low |
| Vite | Laravel plugin + React | Low |
| Components | Preserve structure | Low |

**Ready for Phase 1: Design & Contracts**
