# Native Chat — Phase 1: Resilience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect WebSocket drop ใน <40s (เดิม ~150s) + sync data ทันทีเมื่อ tab visible อีกครั้ง + log subscription errors เพื่อไม่ให้ silent fail

**Architecture:** ขยาย `lib/echo.ts` (singleton Pusher config + module-level visibility handler + subscription error binding) และ `useConnectionStatus.ts` (เพิ่ม listener สำหรับ `echo:resumed` event ที่ trigger query invalidation เหมือน reconnect)

**Tech Stack:** TypeScript, Laravel Echo, Pusher.js, Vitest, jsdom

**Spec reference:** `docs/superpowers/specs/2026-05-03-native-chat-design.md` Section 5 Phase 1

---

## Pre-Flight

- [ ] **Step 0.1: Create branch**

```bash
cd /Users/jaochai/Code/bot-fb
git checkout main
git pull
git checkout -b fix/realtime-resilience
```

- [ ] **Step 0.2: Verify test infra**

Run: `cd frontend && npm run test -- --run src/lib/params.test.ts`
Expected: all tests PASS (verify Vitest works)

---

## File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `frontend/src/lib/echo.ts` | modify | Lower Pusher timeouts; add visibility handler; add subscription_error binding |
| `frontend/src/lib/echo.test.ts` | **create** | Unit test visibility handler dispatches `echo:resumed`; subscription_error dispatches custom event |
| `frontend/src/hooks/useConnectionStatus.ts` | modify | Listen for `echo:resumed` event → invalidate queries (same set as `echo:reconnected`) |
| `frontend/src/hooks/useConnectionStatus.test.ts` | **create** | Unit test echo:resumed handler invalidates queries |

---

## Task 1: Lower Pusher Activity & Pong Timeouts

**Files:**
- Modify: `frontend/src/lib/echo.ts:74-75`

- [ ] **Step 1.1: Read current values to confirm baseline**

Run: `grep -n "activityTimeout\|pongTimeout" frontend/src/lib/echo.ts`
Expected output:
```
74:    activityTimeout: 120000,  // 120 seconds - wait this long for server activity before reconnecting
75:    pongTimeout: 30000,       // 30 seconds - wait this long for pong response after ping
```

- [ ] **Step 1.2: Update timeouts**

Edit `frontend/src/lib/echo.ts` lines 73-75:

```typescript
    // Keep-alive settings to prevent premature disconnection
    // Reverb pings every 25s; 30s buffer detects drops in ~40s total
    activityTimeout: 30000,   // 30s - shorter than previous 120s for faster drop detection
    pongTimeout: 10000,       // 10s - faster pong-failure detection (was 30s)
```

- [ ] **Step 1.3: Verify build still passes**

Run: `cd frontend && npx tsc --noEmit`
Expected: no errors

- [ ] **Step 1.4: Commit**

```bash
git add frontend/src/lib/echo.ts
git commit -m "fix(realtime): lower Pusher activity/pong timeouts for faster drop detection

Reduces WebSocket drop detection from ~150s to ~40s by tightening
activityTimeout 120s→30s and pongTimeout 30s→10s. Reverb's 25s ping
interval keeps connection alive within the new buffer.
"
```

---

## Task 2: Add Visibility Handler That Dispatches `echo:resumed`

**Files:**
- Modify: `frontend/src/lib/echo.ts` (add module-level handler near end of file, before `export const getEcho`)
- Create: `frontend/src/lib/echo.test.ts`

- [ ] **Step 2.1: Write failing test**

Create `frontend/src/lib/echo.test.ts`:

```typescript
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

describe('echo visibility handler', () => {
  let resumedSpy: ReturnType<typeof vi.fn>;

  beforeEach(async () => {
    // Reset module so the visibility listener registers fresh
    vi.resetModules();
    resumedSpy = vi.fn();
    window.addEventListener('echo:resumed', resumedSpy);

    // Importing the module registers the visibilitychange listener
    await import('./echo');
  });

  afterEach(() => {
    window.removeEventListener('echo:resumed', resumedSpy);
  });

  it('dispatches echo:resumed when tab becomes visible', () => {
    Object.defineProperty(document, 'visibilityState', {
      value: 'visible',
      configurable: true,
    });
    document.dispatchEvent(new Event('visibilitychange'));

    expect(resumedSpy).toHaveBeenCalledTimes(1);
  });

  it('does NOT dispatch echo:resumed when tab becomes hidden', () => {
    Object.defineProperty(document, 'visibilityState', {
      value: 'hidden',
      configurable: true,
    });
    document.dispatchEvent(new Event('visibilitychange'));

    expect(resumedSpy).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2.2: Run test to verify it fails**

Run: `cd frontend && npm run test -- --run src/lib/echo.test.ts`
Expected: FAIL with `expected "spy" to be called 1 times, but got 0 times` (handler not registered yet)

- [ ] **Step 2.3: Add visibility handler in echo.ts**

In `frontend/src/lib/echo.ts`, add this block right after the `let echoInstance: Echo<'reverb'> | null = null;` line (around line 150):

```typescript
// Module-level visibility handler — runs once on first import.
// On tab becoming visible: reconnect Echo if disconnected, and ALWAYS dispatch
// echo:resumed so consumers (useConnectionStatus, useRealtime) can refetch
// stale data even when the WebSocket itself stayed alive.
if (typeof document !== 'undefined') {
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;

    const echo = echoInstance;
    if (echo) {
      const state = echo.connector.pusher.connection.state;
      if (state !== 'connected' && state !== 'connecting') {
        echo.connector.pusher.connect();
      }
    }

    window.dispatchEvent(new CustomEvent('echo:resumed'));
  });
}
```

- [ ] **Step 2.4: Run test to verify it passes**

Run: `cd frontend && npm run test -- --run src/lib/echo.test.ts`
Expected: both tests PASS

- [ ] **Step 2.5: Commit**

```bash
git add frontend/src/lib/echo.ts frontend/src/lib/echo.test.ts
git commit -m "feat(realtime): dispatch echo:resumed on tab visibility

When a tab becomes visible again, browsers may have killed or throttled
the WebSocket. Reconnect Echo if disconnected and always notify consumers
via 'echo:resumed' so they can refetch stale data.
"
```

---

## Task 3: Bind `pusher:subscription_error` and Dispatch Custom Event

**Files:**
- Modify: `frontend/src/lib/echo.ts` (inside `createEcho`, near the existing `state_change` bind around line 123)
- Modify: `frontend/src/lib/echo.test.ts`

- [ ] **Step 3.1: Write failing test**

Append to `frontend/src/lib/echo.test.ts` (inside the existing `describe('echo visibility handler', ...)` or as a new describe — use new describe for clarity):

```typescript
describe('echo subscription_error', () => {
  it('dispatches echo:subscription_error when Pusher emits subscription_error', async () => {
    vi.resetModules();
    const errSpy = vi.fn();
    window.addEventListener('echo:subscription_error', errSpy as EventListener);

    const { getEcho } = await import('./echo');
    const echo = getEcho();
    // Simulate Pusher emitting subscription_error
    // @ts-expect-error - accessing internal pusher emitter for test
    echo.connector.pusher.emit('pusher:subscription_error', { type: 'AuthError', error: 'invalid token' });

    expect(errSpy).toHaveBeenCalledTimes(1);
    const event = errSpy.mock.calls[0][0] as CustomEvent;
    expect(event.detail).toMatchObject({ type: 'AuthError' });

    window.removeEventListener('echo:subscription_error', errSpy as EventListener);
  });
});
```

- [ ] **Step 3.2: Run test to verify it fails**

Run: `cd frontend && npm run test -- --run src/lib/echo.test.ts`
Expected: FAIL — subscription_error spy not called (handler doesn't exist yet)

- [ ] **Step 3.3: Add subscription_error binding inside `createEcho`**

In `frontend/src/lib/echo.ts`, locate the existing `echo.connector.pusher.connection.bind('state_change', ...)` block (around lines 123-144). Add this AFTER that block, still inside `createEcho`:

```typescript
  // Log + dispatch subscription errors so silent auth failures are visible
  echo.connector.pusher.bind('pusher:subscription_error', (data: unknown) => {
    console.warn('[echo] subscription_error', data);
    window.dispatchEvent(new CustomEvent('echo:subscription_error', { detail: data }));
  });
```

- [ ] **Step 3.4: Run test to verify it passes**

Run: `cd frontend && npm run test -- --run src/lib/echo.test.ts`
Expected: all tests PASS (visibility + subscription_error)

- [ ] **Step 3.5: Commit**

```bash
git add frontend/src/lib/echo.ts frontend/src/lib/echo.test.ts
git commit -m "feat(realtime): log + broadcast pusher subscription errors

Subscription auth failures (expired tokens, channel auth fail) were
silent. Now they are warned in console and dispatched as
'echo:subscription_error' so higher layers can act (e.g., refresh token).
"
```

---

## Task 4: useConnectionStatus Listens for `echo:resumed`

**Files:**
- Modify: `frontend/src/hooks/useConnectionStatus.ts`
- Create: `frontend/src/hooks/useConnectionStatus.test.ts`

- [ ] **Step 4.1: Write failing test**

Create `frontend/src/hooks/useConnectionStatus.test.tsx` (`.tsx` because the wrapper uses JSX):

```typescript
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import React from 'react';
import { useConnectionStatus } from './useConnectionStatus';

describe('useConnectionStatus', () => {
  let queryClient: QueryClient;
  let invalidateSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    queryClient = new QueryClient();
    invalidateSpy = vi.fn();
    queryClient.invalidateQueries = invalidateSpy as unknown as QueryClient['invalidateQueries'];
  });

  const wrapper = ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );

  it('invalidates conversation/messages queries when echo:resumed fires', () => {
    renderHook(() => useConnectionStatus(), { wrapper });

    window.dispatchEvent(new CustomEvent('echo:resumed'));

    expect(invalidateSpy).toHaveBeenCalledTimes(1);
    const call = invalidateSpy.mock.calls[0][0];
    expect(call).toHaveProperty('predicate');
  });
});
```

- [ ] **Step 4.2: Run test to verify it fails**

Run: `cd frontend && npm run test -- --run src/hooks/useConnectionStatus.test.tsx`
Expected: FAIL — `expected "spy" to be called 1 times, but got 0 times` (no echo:resumed listener yet)

- [ ] **Step 4.3: Add echo:resumed listener in useConnectionStatus.ts**

Edit `frontend/src/hooks/useConnectionStatus.ts`. Locate the existing `useEffect` block. Inside it, after the existing `window.addEventListener('echo:reconnected', handleReconnected);` line (line 61), add:

```typescript
    // Same handler as echo:reconnected: invalidate stale data.
    // Fires when tab becomes visible (WebSocket may or may not have stayed alive).
    window.addEventListener('echo:resumed', handleReconnected);
```

And in the cleanup function (after line 66), add:

```typescript
      window.removeEventListener('echo:resumed', handleReconnected);
```

- [ ] **Step 4.4: Run test to verify it passes**

Run: `cd frontend && npm run test -- --run src/hooks/useConnectionStatus.test.tsx`
Expected: PASS

- [ ] **Step 4.5: Run full test suite + lint + tsc**

Run: `cd frontend && npm run test && npm run lint && npx tsc --noEmit`
Expected: all PASS, no errors

- [ ] **Step 4.6: Commit**

```bash
git add frontend/src/hooks/useConnectionStatus.ts frontend/src/hooks/useConnectionStatus.test.tsx
git commit -m "feat(realtime): invalidate queries on echo:resumed event

useConnectionStatus now listens for echo:resumed (dispatched by
visibility handler) and invalidates the same query set as on
echo:reconnected. Ensures fresh data when user returns to tab.
"
```

---

## Task 5: Run `/simplify` on Changed Files

**Files:**
- Review: `frontend/src/lib/echo.ts`, `frontend/src/lib/echo.test.ts`, `frontend/src/hooks/useConnectionStatus.ts`, `frontend/src/hooks/useConnectionStatus.test.tsx`

- [ ] **Step 5.1: Invoke simplify skill**

Use the Skill tool with `skill: simplify`. Provide context: "Review files modified in branch fix/realtime-resilience: frontend/src/lib/echo.ts, frontend/src/lib/echo.test.ts, frontend/src/hooks/useConnectionStatus.ts, frontend/src/hooks/useConnectionStatus.test.tsx"

- [ ] **Step 5.2: Apply suggestions if any**

If `/simplify` proposes changes:
- Review each suggestion
- Apply only those that improve clarity without changing behavior
- Re-run tests: `cd frontend && npm run test`

- [ ] **Step 5.3: Commit (only if simplify made changes)**

```bash
# Skip if no simplify changes
git add -A
git commit -m "refactor(realtime): apply simplify pass to phase-1 changes"
```

---

## Task 6: Manual Smoke Test

**Files:** none (verification only)

- [ ] **Step 6.1: Start backend + frontend dev**

In one terminal: `cd backend && php artisan serve`
In second: `cd backend && php artisan reverb:start`
In third: `cd backend && php artisan queue:listen`
In fourth: `cd frontend && npm run dev`

- [ ] **Step 6.2: Test tab visibility resume**

1. Open browser to `http://localhost:5173/chat`
2. Pick a bot with active conversations
3. Open DevTools → Console
4. Switch to another tab for >30 seconds
5. Switch back

Expected console output:
- No errors
- Connection state stays `connected` (or briefly `connecting` then `connected`)
- Conversations list refreshes (Network tab shows GET /conversations request)

- [ ] **Step 6.3: Test connection drop detection (manual disconnect)**

1. With chat open, in DevTools → Network → throttle to "Offline"
2. Wait 40-60 seconds
3. Watch console for Pusher disconnect logs
4. Set Network back to "No throttling"

Expected:
- Connection drop detected within ~40s (was ~150s before this PR)
- Auto-reconnect succeeds
- `echo:reconnected` event fires (queries refetch)

- [ ] **Step 6.4: Document smoke test result**

If issues found: open issue, do NOT push. Diagnose and fix.
If passes: proceed to PR.

---

## Task 7: Open PR

**Files:** none (workflow only)

- [ ] **Step 7.1: Push branch**

```bash
git push -u origin fix/realtime-resilience
```

- [ ] **Step 7.2: Create PR**

```bash
gh pr create --title "fix(realtime): resilience layer (Phase 1)" --body "$(cat <<'EOF'
## Summary
- Lower Pusher activityTimeout (120s→30s) and pongTimeout (30s→10s) for faster WebSocket drop detection
- Add module-level visibilitychange handler — reconnects on disconnect and dispatches \`echo:resumed\`
- Bind \`pusher:subscription_error\` → log + dispatch \`echo:subscription_error\` (was silent before)
- \`useConnectionStatus\` listens for \`echo:resumed\` → invalidates conversation/message queries

Phase 1 of the Native Chat plan. See \`docs/superpowers/specs/2026-05-03-native-chat-design.md\` and \`docs/superpowers/plans/2026-05-03-native-chat-phase-1-resilience.md\`.

## Test plan
- [ ] Vitest unit tests pass (\`npm run test\`)
- [ ] Lint + TypeScript pass
- [ ] Manual: tab background 30s → return → conversations refetch
- [ ] Manual: DevTools throttle Offline → drop detected within 40s
- [ ] Sentry: monitor reconnect rate after deploy (should not spike from false-positives)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 7.3: Verify CI green**

Wait for "Backend Tests" and "Frontend Checks" CI to complete.
Expected: both PASS.

If FAIL: investigate, push fix as new commit (do NOT amend).

---

## Definition of Done (Phase 1)

- [x] All 7 tasks complete
- [ ] PR merged to `main`
- [ ] Railway auto-deploy succeeds
- [ ] Sentry shows no spike in reconnect errors after deploy (24h observation window)
- [ ] Manual: tab background 5min → return → message visible <2s

---

## Self-Review Checklist (run before handoff)

- [x] Spec coverage: timeouts, visibility handler, subscription_error, useConnectionStatus listener — all in Section 5 Phase 1 of spec ✓
- [x] No placeholders (TBD, TODO, "implement later") ✓
- [x] Type/method names consistent across tasks (`echo:resumed`, `echo:subscription_error`) ✓
- [x] Each step has actual code or exact command ✓
- [x] Pre-commit `/simplify` requirement included (Task 5) ✓
