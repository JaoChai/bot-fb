# React Doctor — Known False Positives

Diagnostics in this list have been verified as false positives during the
2026-05-28 triage. The rule fires but the underlying code is correct.

---

## `react-doctor/effect-needs-cleanup` — Laravel Echo `leave()`

**Files:**
- `frontend/src/hooks/useEcho.ts` — `useBotChannel`, `useKnowledgeBaseChannel`

The walker looks for `.unsubscribe()` / `.remove*()` style teardowns inside
the effect's return. Laravel Echo's canonical teardown is
`echo.leave(channelName)`, which the walker doesn't recognise. The effects
all return a cleanup function that calls `leave()`, so subscriptions are
released on re-run and unmount.

Skip after verifying the effect returns `() => { echo.leave(...) }`.

---

## `react-doctor/only-export-components` — shadcn / router conventions

**Files:**
- `frontend/src/components/ui/button.tsx` — `buttonVariants` (CVA variants)
- `frontend/src/components/ui/badge.tsx` — `badgeVariants` (CVA variants)
- `frontend/src/router.tsx` — `LazyPage` wrapper + `router` config

Standard shadcn/ui ships every component with its `*Variants` export so
consumers can compose styles. `router.tsx` exports a React Router config
object alongside its `LazyPage` helper. Both files already carry
`/* eslint-disable react-refresh/only-export-components */`. The Fast
Refresh DX trade-off is intentional.

---

## `deslop/unused-export` — barrel re-exports

The deslop walker doesn't follow barrel `index.ts` re-exports. ~27
exports are flagged but actually consumed via:
- `frontend/src/hooks/chat/index.ts`
- `frontend/src/hooks/conversations/index.ts`
- `frontend/src/components/chat/adapters/index.ts`

Skip when the export is referenced in a sibling `index.ts` that re-exports
it (`export { foo } from './bar'`) — the barrel itself is consumed by a
real call site.

23 genuinely-dead exports were removed during the 2026-05-28 sweep; the
remaining 27 are barrel re-exports.
