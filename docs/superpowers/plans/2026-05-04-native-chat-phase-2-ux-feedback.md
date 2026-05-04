# Native Chat — Phase 2: UX Feedback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ให้ user "รู้สึก" ว่า chat ทำงานแบบ native — เห็นสถานะ connection, เห็น pending/failed message, ได้ยินเสียง + notification เมื่อ tab inactive

**Architecture:** สร้าง 3 ไฟล์ใหม่ (ConnectionIndicator, notifications lib, useBrowserNotification hook) + แก้ 4 ไฟล์เดิม (MessageBubble, ChatHeader, useRealtime, uiStore) — ใช้ pattern "extend existing" ตามแนว Approach 1 ของ spec

**Tech Stack:** React 19, TypeScript, Tailwind v4, Zustand, Web Notification API, Web Audio API, Vitest

**Spec reference:** `docs/superpowers/specs/2026-05-03-native-chat-design.md` Section 5 Phase 2

**Depends on:** Phase 1 merged (PR #144 ✅)

---

## Pre-Flight

- [ ] **Step 0.1: Create branch from main (Phase 1 already merged)**

```bash
cd /Users/jaochai/Code/bot-fb
git checkout main && git pull
git checkout -b feat/chat-ux-feedback
```

- [ ] **Step 0.2: Verify test infra**

Run: `cd frontend && npm run test -- --run src/lib/params.test.ts`
Expected: PASS

---

## File Structure

| File | Action | Responsibility |
|------|--------|---------------|
| `frontend/src/components/chat/ConnectionIndicator.tsx` | **create** | Badge ด้าน header — เขียว/แดง แสดงสถานะ WebSocket |
| `frontend/src/components/chat/ConnectionIndicator.test.tsx` | **create** | Unit test 2 states |
| `frontend/src/lib/notifications.ts` | **create** | requestPermission, showNotification, playPing, setUnreadBadge |
| `frontend/src/lib/notifications.test.ts` | **create** | Unit test notification functions |
| `frontend/src/components/chat/ChatHeader.tsx` | modify | เพิ่ม ConnectionIndicator |
| `frontend/src/components/chat/MessageBubble.tsx` | modify | Pending state (clock + opacity) เมื่อ id < 0 |
| `frontend/src/hooks/chat/useRealtime.ts` | modify | เรียก notification/ping/badge เมื่อ tab not visible |
| `frontend/src/stores/uiStore.ts` | modify | เพิ่ม audioEnabled, notificationEnabled toggles (persist) |

---

## Task 1: ConnectionIndicator Component

**Files:**
- Create: `frontend/src/components/chat/ConnectionIndicator.tsx`
- Create: `frontend/src/components/chat/ConnectionIndicator.test.tsx`
- Modify: `frontend/src/components/chat/ChatHeader.tsx`

- [ ] **Step 1.1: Write failing test**

Create `frontend/src/components/chat/ConnectionIndicator.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ConnectionIndicator } from './ConnectionIndicator';

vi.mock('@/stores/connectionStore', () => ({
  useConnectionStore: vi.fn(),
}));

import { useConnectionStore } from '@/stores/connectionStore';

describe('ConnectionIndicator', () => {
  it('shows green dot when connected', () => {
    vi.mocked(useConnectionStore).mockImplementation((selector: (s: { isConnected: boolean }) => boolean) =>
      selector({ isConnected: true })
    );
    render(<ConnectionIndicator />);
    const dot = screen.getByTestId('connection-dot');
    expect(dot.className).toContain('bg-green');
  });

  it('shows red dot when disconnected', () => {
    vi.mocked(useConnectionStore).mockImplementation((selector: (s: { isConnected: boolean }) => boolean) =>
      selector({ isConnected: false })
    );
    render(<ConnectionIndicator />);
    const dot = screen.getByTestId('connection-dot');
    expect(dot.className).toContain('bg-red');
  });
});
```

- [ ] **Step 1.2: Run test to verify it fails**

Run: `cd frontend && npm run test -- --run src/components/chat/ConnectionIndicator.test.tsx`

- [ ] **Step 1.3: Create ConnectionIndicator component**

Create `frontend/src/components/chat/ConnectionIndicator.tsx`:

```tsx
import { useConnectionStore } from '@/stores/connectionStore';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export function ConnectionIndicator() {
  const isConnected = useConnectionStore((s) => s.isConnected);

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="flex items-center gap-1.5 text-xs text-muted-foreground cursor-default">
            <span
              data-testid="connection-dot"
              className={cn(
                'h-2 w-2 rounded-full transition-colors',
                isConnected ? 'bg-green-500' : 'bg-red-500 animate-pulse'
              )}
            />
          </span>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          {isConnected ? 'เชื่อมต่อแล้ว — ข้อมูลอัพเดทอัตโนมัติ' : 'ขาดการเชื่อมต่อ — กำลังเชื่อมใหม่...'}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
```

- [ ] **Step 1.4: Run test to verify it passes**

- [ ] **Step 1.5: Wire into ChatHeader — add `<ConnectionIndicator />` before handover controls**

- [ ] **Step 1.6: Verify tsc + full tests**

- [ ] **Step 1.7: Commit**

```
feat(chat): add connection status indicator in chat header
```

---

## Task 2: Pending Message Visual in MessageBubble

**Files:**
- Modify: `frontend/src/components/chat/MessageBubble.tsx`

- [ ] **Step 2.1: Add pending visual for optimistic messages (id < 0)**

Import `Clock` from lucide-react. Add `const isPending = message.id < 0;` after `isUser`. Add `isPending && 'opacity-60'` to bubble className. Add clock icon + "กำลังส่ง..." text after AI metadata.

- [ ] **Step 2.2: Verify build** — `npx tsc --noEmit`

- [ ] **Step 2.3: Commit**

```
feat(chat): show pending indicator for optimistic messages
```

---

## Task 3: Notification Library

**Files:**
- Create: `frontend/src/lib/notifications.ts`
- Create: `frontend/src/lib/notifications.test.ts`

- [ ] **Step 3.1: Write failing test** for requestNotificationPermission + setUnreadBadge

- [ ] **Step 3.2: Run test to verify it fails**

- [ ] **Step 3.3: Create notifications library**

```typescript
const BASE_TITLE = 'BotJao';
let audioContext: AudioContext | null = null;

export async function requestNotificationPermission(): Promise<NotificationPermission> {
  if (!('Notification' in window)) return 'denied';
  if (Notification.permission !== 'default') return Notification.permission;
  return Notification.requestPermission();
}

export function showBrowserNotification(title: string, options?: NotificationOptions): void {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  try { new Notification(title, { icon: '/favicon.ico', ...options }); } catch { /* silent */ }
}

export function playPing(): void {
  try {
    if (!audioContext) audioContext = new AudioContext();
    const osc = audioContext.createOscillator();
    const gain = audioContext.createGain();
    osc.connect(gain);
    gain.connect(audioContext.destination);
    osc.frequency.value = 800;
    osc.type = 'sine';
    gain.gain.value = 0.1;
    gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.3);
    osc.start(audioContext.currentTime);
    osc.stop(audioContext.currentTime + 0.3);
  } catch { /* silent */ }
}

export function setUnreadBadge(count: number): void {
  document.title = count > 0 ? `(${count}) ${BASE_TITLE}` : BASE_TITLE;
}
```

- [ ] **Step 3.4: Run test to verify passes**

- [ ] **Step 3.5: Commit**

```
feat(chat): add notification library (browser notification + audio + badge)
```

---

## Task 4: Notification Settings in uiStore

**Files:**
- Modify: `frontend/src/stores/uiStore.ts`

- [ ] **Step 4.1: Add to UIState:** `audioEnabled: boolean; notificationEnabled: boolean;`
- [ ] **Step 4.2: Add to UIActions:** `setAudioEnabled, setNotificationEnabled`
- [ ] **Step 4.3: Add initial values:** both `false` (opt-in)
- [ ] **Step 4.4: Add to partialize** (persist to localStorage)
- [ ] **Step 4.5: Verify tsc**
- [ ] **Step 4.6: Commit**

```
feat(chat): add audio/notification toggle state in uiStore
```

---

## Task 5: Wire Notifications into useRealtime

**Files:**
- Modify: `frontend/src/hooks/chat/useRealtime.ts`

- [ ] **Step 5.1: Import** showBrowserNotification, playPing, setUnreadBadge from notifications lib + useUIStore
- [ ] **Step 5.2: Add refs** for unreadCount, audioEnabled, notificationEnabled
- [ ] **Step 5.3: In handleRealtimeMessage**, after cache updates:
  - If `document.visibilityState === 'hidden' && event.sender !== 'agent'`: increment badge, play ping, show notification
- [ ] **Step 5.4: Add visibilitychange listener** to reset badge when tab visible
- [ ] **Step 5.5: Verify tsc + test**
- [ ] **Step 5.6: Commit**

```
feat(chat): trigger notification + audio + badge on new messages
```

---

## Task 6: /simplify + Push + PR

- [ ] **Step 6.1:** Run `/simplify` on all changed files
- [ ] **Step 6.2:** Re-run tests + tsc
- [ ] **Step 6.3:** Push branch + create PR

```
feat(chat): UX feedback — connection indicator, pending messages, notifications
```

## Definition of Done
- [ ] All tasks complete
- [ ] CI green
- [ ] Manual: connection indicator green/red correct
- [ ] Manual: pending clock visible on send
- [ ] Manual: notification fires when tab hidden
