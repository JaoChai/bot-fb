# Chat Mobile Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ทำให้หน้าแชทใช้งานได้จริงบนมือถือ — ปุ่มย้อนกลับกดได้ แถบพิมพ์ไม่จมใต้ home indicator และคีย์บอร์ดไม่บังช่องพิมพ์

**Architecture:** ต้นเหตุคือ `ChatPage` สู้กับ `RootLayout` ด้วย negative margin (`-mt-14`) จนไปมุดใต้ `Header` ที่เป็น `sticky z-40` แก้ที่ต้นทางโดยให้ `RootLayout` รู้จัก route ที่กินเต็มพื้นที่ แล้ว `ChatPage` ใช้ `h-full` ตรงๆ บนมือถือเมื่อเปิดห้องแชท `Header` ของแอปจะหลบให้ `ChatHeader` ทำหน้าที่ nav แทน (แบบ LINE/Messenger) ส่วนคีย์บอร์ดจัดการด้วย `visualViewport` API และ safe area ด้วย `env()` ตามแพตเทิร์นที่โปรเจกต์ใช้อยู่แล้ว

**Tech Stack:** React 19, TypeScript, Tailwind, Zustand, Radix UI (shadcn), Vitest + Testing Library + jsdom

**Spec:** `docs/superpowers/specs/2026-07-31-chat-mobile-design.md`

## Global Constraints

- **ฝั่ง desktop (≥768px) ต้องไม่เปลี่ยนหน้าตาเลยสักพิกเซล** — ทุก task ต้องเช็คข้อนี้
- Breakpoint ที่ใช้: `sm` = 640px, `md` = 768px, `xl` = 1280px (ค่า default ของ Tailwind)
- Touch target บนมือถือขั้นต่ำ 44×44px ระยะห่างระหว่างปุ่มขั้นต่ำ 8px (`gap-2`)
- safe area ใช้ `env(safe-area-inset-*, 0px)` ตรงๆ ตามแพตเทิร์นใน `frontend/src/components/connections/StickyActionBar.tsx:13` — **ห้ามใช้ utility `.pb-safe`** เพราะมันเซ็ต `padding-bottom` ทับ padding ของคอมโพเนนต์เอง
- คำสั่ง: `cd frontend` ก่อนเสมอ — `npm run test`, `npm run build`, `npm run lint`
- ทุก test file วางข้างไฟล์ที่มันทดสอบ (`Foo.tsx` → `Foo.test.tsx`) ตามที่โปรเจกต์ทำอยู่
- jsdom **ไม่มี** `window.matchMedia` และ **ไม่มี** `window.visualViewport` — test ที่แตะ 2 อย่างนี้ต้อง stub เองในไฟล์ test นั้น (อย่าไปแก้ `src/test/setup.ts` ที่ใช้ร่วมกัน)

## หมายเหตุเรื่องการทดสอบ layout

jsdom **ไม่มี layout engine** — `getBoundingClientRect()` คืน 0 ทุกค่า ดังนั้น **ห้ามเขียน unit test ที่อ้างว่าตรวจตำแหน่งจริง** เพราะมันจะผ่านทั้งที่ของพัง

แบ่งวิธีตรวจเป็น 2 ชั้น:
1. **Vitest** — ตรวจ *การตัดสินใจ* ที่เป็น logic จริง (header ควรโผล่ไหม, hook คืนค่าเท่าไร, เมนูมีรายการอะไร)
2. **Geometry repro ใน scratchpad** (Task 7) — ตรวจ *ตำแหน่งจริง* ด้วยเบราว์เซอร์จริงที่ 390×844 ไฟล์นี้ **ไม่ commit** เข้า repo

---

## Task 1: hook `useKeyboardInset`

Hook อ่านความสูงที่คีย์บอร์ดจอสัมผัสกินไปจาก `visualViewport` — Task 2 จะเอาไปหักความสูงหน้าแชท

**Files:**
- Create: `frontend/src/hooks/useKeyboardInset.ts`
- Test: `frontend/src/hooks/useKeyboardInset.test.ts`

**Interfaces:**
- Consumes: (ไม่มี — task แรก)
- Produces: `useKeyboardInset(): number` — คืนจำนวน px ที่คีย์บอร์ดกินจากขอบล่าง คืน `0` เมื่อคีย์บอร์ดปิดหรือเบราว์เซอร์ไม่รองรับ `visualViewport`

- [ ] **Step 1: เขียน test ที่ยังไม่ผ่าน**

สร้าง `frontend/src/hooks/useKeyboardInset.test.ts`:

```ts
import { describe, it, expect, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useKeyboardInset } from './useKeyboardInset';

const ORIGINAL_INNER_HEIGHT = window.innerHeight;

/** jsdom ไม่มี visualViewport — ต่อ stub ที่จำ listener ไว้ให้ยิงเองได้ */
function stubVisualViewport(height: number, offsetTop = 0) {
  const listeners: Record<string, Set<() => void>> = {
    resize: new Set(),
    scroll: new Set(),
  };
  const vv = {
    height,
    offsetTop,
    addEventListener: (type: string, fn: () => void) => listeners[type]?.add(fn),
    removeEventListener: (type: string, fn: () => void) => listeners[type]?.delete(fn),
  };
  Object.defineProperty(window, 'visualViewport', {
    value: vv,
    configurable: true,
    writable: true,
  });
  return {
    vv,
    fire: (type: 'resize' | 'scroll') => listeners[type].forEach((fn) => fn()),
    listenerCount: () => listeners.resize.size + listeners.scroll.size,
  };
}

function setInnerHeight(px: number) {
  Object.defineProperty(window, 'innerHeight', {
    value: px,
    configurable: true,
    writable: true,
  });
}

afterEach(() => {
  Object.defineProperty(window, 'visualViewport', {
    value: undefined,
    configurable: true,
    writable: true,
  });
  setInnerHeight(ORIGINAL_INNER_HEIGHT);
});

describe('useKeyboardInset', () => {
  it('คืน 0 ตอนคีย์บอร์ดปิด (visual viewport เท่ากับ layout viewport)', () => {
    setInnerHeight(844);
    stubVisualViewport(844);

    const { result } = renderHook(() => useKeyboardInset());

    expect(result.current).toBe(0);
  });

  it('คืนความสูงที่คีย์บอร์ดกินตอนเปิด', () => {
    setInnerHeight(844);
    const { fire, vv } = stubVisualViewport(844);

    const { result } = renderHook(() => useKeyboardInset());

    act(() => {
      vv.height = 508;
      fire('resize');
    });

    expect(result.current).toBe(336);
  });

  it('บวก offsetTop ด้วย เพราะ iOS เลื่อน visual viewport ไม่ได้หดอย่างเดียว', () => {
    setInnerHeight(844);
    const { fire, vv } = stubVisualViewport(844);

    const { result } = renderHook(() => useKeyboardInset());

    act(() => {
      vv.height = 508;
      vv.offsetTop = 100;
      fire('scroll');
    });

    // 844 - (508 + 100) = 236
    expect(result.current).toBe(236);
  });

  it('ไม่คืนค่าติดลบเมื่อ visual viewport ใหญ่กว่า layout viewport', () => {
    setInnerHeight(844);
    const { fire, vv } = stubVisualViewport(844);

    const { result } = renderHook(() => useKeyboardInset());

    act(() => {
      vv.height = 900;
      fire('resize');
    });

    expect(result.current).toBe(0);
  });

  it('คืน 0 เมื่อเบราว์เซอร์ไม่มี visualViewport', () => {
    setInnerHeight(844);

    const { result } = renderHook(() => useKeyboardInset());

    expect(result.current).toBe(0);
  });

  it('ถอด listener ตอน unmount', () => {
    setInnerHeight(844);
    const { listenerCount } = stubVisualViewport(844);

    const { unmount } = renderHook(() => useKeyboardInset());
    expect(listenerCount()).toBe(2);

    unmount();
    expect(listenerCount()).toBe(0);
  });
});
```

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

```bash
cd frontend && npm run test -- src/hooks/useKeyboardInset.test.ts
```

Expected: FAIL — `Failed to resolve import "./useKeyboardInset"`

- [ ] **Step 3: เขียน implementation ให้น้อยที่สุดที่ผ่าน**

สร้าง `frontend/src/hooks/useKeyboardInset.ts`:

```ts
import { useEffect, useState } from 'react';

/**
 * ความสูง (px) ที่คีย์บอร์ดจอสัมผัสกินไปจากขอบล่างของ layout viewport
 *
 * iOS Safari ไม่หด `dvh` เมื่อคีย์บอร์ดเปิด และไม่ได้แค่หด visual viewport
 * แต่เลื่อนมันขึ้นด้วย จึงต้องคิด `offsetTop` เข้าไปในสมการ และต้องฟัง
 * `scroll` ควบคู่กับ `resize`
 *
 * คืน 0 เสมอบน desktop และบนเบราว์เซอร์ที่ไม่มี `visualViewport`
 */
export function useKeyboardInset(): number {
  const [inset, setInset] = useState(0);

  useEffect(() => {
    const vv = window.visualViewport;
    if (!vv) return;

    const update = () => {
      const gap = window.innerHeight - (vv.height + vv.offsetTop);
      setInset(Math.max(0, Math.round(gap)));
    };

    update();
    vv.addEventListener('resize', update);
    vv.addEventListener('scroll', update);

    return () => {
      vv.removeEventListener('resize', update);
      vv.removeEventListener('scroll', update);
    };
  }, []);

  return inset;
}
```

- [ ] **Step 4: รัน test ให้ผ่าน**

```bash
cd frontend && npm run test -- src/hooks/useKeyboardInset.test.ts
```

Expected: PASS ทั้ง 6 เคส

- [ ] **Step 5: commit**

```bash
git add frontend/src/hooks/useKeyboardInset.ts frontend/src/hooks/useKeyboardInset.test.ts
git commit -m "feat(chat): hook อ่านความสูงคีย์บอร์ดจาก visualViewport"
```

---

## Task 2: รื้อ layout ที่ตีกัน (แก้ P0-1 + P1-6)

หัวใจของงานทั้งหมด — เอา negative margin ออกจาก `ChatPage` แล้วให้ `RootLayout` จัดการพื้นที่ให้ถูกต้องแทน

**Files:**
- Modify: `frontend/src/components/layout/RootLayout.tsx` (ทั้งไฟล์ 24 บรรทัด)
- Modify: `frontend/src/pages/ChatPage.tsx:163`, `frontend/src/pages/ChatPage.tsx:188`
- Test: `frontend/src/components/layout/RootLayout.test.tsx`

**Interfaces:**
- Consumes: `useKeyboardInset(): number` จาก Task 1
- Produces: `RootLayout` ที่ซ่อน `<Header/>` เมื่ออยู่ในห้องแชทบนมือถือ และไม่ใส่ padding ให้ `<main>` บน route `/chat`

- [ ] **Step 1: เขียน test ที่ยังไม่ผ่าน**

สร้าง `frontend/src/components/layout/RootLayout.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RootLayout } from './RootLayout';
import { useChatStore } from '@/stores/chatStore';

// Sidebar กับ useConnectionStatus ไม่เกี่ยวกับสิ่งที่ test นี้ตรวจ และลาก
// dependency (auth, Echo) มาเต็มไปหมด — mock ทิ้งให้ test อ่านง่าย
vi.mock('./Sidebar', () => ({ Sidebar: () => <aside data-testid="sidebar" /> }));
vi.mock('@/hooks/useConnectionStatus', () => ({ useConnectionStatus: () => {} }));

function renderAt(path: string) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route element={<RootLayout />}>
            <Route path="/chat" element={<div>chat page</div>} />
            <Route path="/dashboard" element={<div>dashboard page</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  );
}

describe('RootLayout', () => {
  beforeEach(() => {
    useChatStore.setState({ showMobileChat: false });
  });

  it('แสดง Header ในหน้ารายการแชท (ยังไม่ได้เปิดห้องแชท)', () => {
    useChatStore.setState({ showMobileChat: false });
    renderAt('/chat');

    expect(screen.getByRole('banner')).toBeInTheDocument();
  });

  it('ซ่อน Header เมื่อเปิดห้องแชทบนมือถือ ให้ ChatHeader ทำหน้าที่ nav แทน', () => {
    useChatStore.setState({ showMobileChat: true });
    renderAt('/chat');

    expect(screen.queryByRole('banner')).not.toBeInTheDocument();
  });

  it('ไม่ซ่อน Header ในหน้าอื่น แม้ showMobileChat ยังค้างเป็น true', () => {
    useChatStore.setState({ showMobileChat: true });
    renderAt('/dashboard');

    expect(screen.getByRole('banner')).toBeInTheDocument();
  });

  it('ไม่ใส่ padding ให้ main ในหน้าแชท เพราะหน้าแชทจัดการพื้นที่เอง', () => {
    const { container } = renderAt('/chat');
    const main = container.querySelector('main');

    expect(main).toHaveClass('overflow-hidden');
    expect(main).not.toHaveClass('p-4');
    expect(main).not.toHaveClass('md:p-6');
  });

  it('ยังใส่ padding ให้ main ในหน้าอื่นเหมือนเดิม', () => {
    const { container } = renderAt('/dashboard');
    const main = container.querySelector('main');

    expect(main).toHaveClass('p-4');
    expect(main).toHaveClass('md:p-6');
    expect(main).toHaveClass('overflow-auto');
  });
});
```

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

```bash
cd frontend && npm run test -- src/components/layout/RootLayout.test.tsx
```

Expected: FAIL 3 เคส — เคส "ซ่อน Header" fail เพราะยังไม่มี logic, เคส padding fail เพราะ `main` ยังมี `p-4 md:p-6` เสมอ

- [ ] **Step 3: แก้ `RootLayout.tsx`**

เขียนทับทั้งไฟล์ `frontend/src/components/layout/RootLayout.tsx`:

```tsx
import { Outlet, useLocation } from "react-router"
import { Sidebar } from "./Sidebar"
import { Header } from "./Header"
import { useConnectionStatus } from "@/hooks/useConnectionStatus"
import { useChatStore } from "@/stores/chatStore"
import { cn } from "@/lib/utils"

export function RootLayout() {
  // Monitor WebSocket connection globally - shows toast on disconnect/reconnect
  useConnectionStatus();

  const { pathname } = useLocation();
  const showMobileChat = useChatStore((s) => s.showMobileChat);

  // หน้าแชทจัดการพื้นที่ทั้งหมดเอง (สกรอลล์แยกซ้าย/ขวา) จึงไม่รับ padding จาก main
  const isChatRoute = pathname.startsWith('/chat');
  // อยู่ในห้องแชทบนมือถือ = เต็มจอแบบ LINE ให้ ChatHeader ทำหน้าที่ nav แทน
  const hideMobileHeader = isChatRoute && showMobileChat;

  return (
    <div className="flex h-dvh bg-background">
      {/* Sidebar - hidden on mobile */}
      <Sidebar />

      {/* Main content area */}
      <div className="flex flex-1 flex-col overflow-hidden">
        {!hideMobileHeader && <Header />}
        <main
          className={cn(
            "flex-1 min-h-0",
            isChatRoute ? "overflow-hidden" : "overflow-auto p-4 md:p-6"
          )}
        >
          <Outlet />
        </main>
      </div>
    </div>
  )
}
```

- [ ] **Step 4: รัน test ให้ผ่าน**

```bash
cd frontend && npm run test -- src/components/layout/RootLayout.test.tsx
```

Expected: PASS ทั้ง 5 เคส

- [ ] **Step 5: เอา negative margin ออกจาก `ChatPage`**

ที่ `frontend/src/pages/ChatPage.tsx` เพิ่ม import:

```tsx
import { useKeyboardInset } from '@/hooks/useKeyboardInset';
```

เพิ่มบรรทัดนี้ในตัว component (วางหลัง `const { toast } = useToast();` บรรทัด 31):

```tsx
  // คีย์บอร์ดจอสัมผัสกินพื้นที่จากขอบล่าง — หักออกจากความสูงหน้าแชท
  const keyboardInset = useKeyboardInset();
```

แก้บรรทัด 163 (state ยังไม่เลือกบอท) จาก:

```tsx
      <div className="flex h-[calc(100dvh-3.5rem)] md:h-[calc(100dvh-64px)] items-center justify-center p-6">
```

เป็น:

```tsx
      <div className="flex h-full items-center justify-center p-6">
```

แก้บรรทัด 188 จาก:

```tsx
    <div className="-mx-4 -mb-4 -mt-14 md:-m-6 flex h-[calc(100%+4.5rem)] md:h-[calc(100%+3rem)] overflow-hidden bg-background">
```

เป็น:

```tsx
    <div
      className="flex h-full overflow-hidden bg-background pl-[env(safe-area-inset-left,0px)] pr-[env(safe-area-inset-right,0px)]"
      style={keyboardInset > 0 ? { height: `calc(100% - ${keyboardInset}px)` } : undefined}
    >
```

หมายเหตุ: inline style ทับ `h-full` เฉพาะตอนคีย์บอร์ดเปิด ตอนปกติ (`inset` = 0) ส่ง `undefined` เพื่อให้ class ทำงานตามเดิม — safe area ซ้าย/ขวาไว้กัน notch ตอนถือแนวนอน

- [ ] **Step 6: รัน test ทั้งชุด + build + lint**

```bash
cd frontend && npm run test && npm run build && npm run lint
```

Expected: test ผ่านหมด, build ผ่าน, lint ไม่มี error

- [ ] **Step 7: commit**

```bash
git add frontend/src/components/layout/RootLayout.tsx frontend/src/components/layout/RootLayout.test.tsx frontend/src/pages/ChatPage.tsx
git commit -m "fix(chat): เลิกดึงหน้าแชทไปมุดใต้ header ด้วย negative margin

ChatPage ใช้ -mt-14 ดึงตัวเองขึ้นไปทับ Header ที่เป็น sticky z-40
ทำให้ปุ่มย้อนกลับเหลือพื้นที่กดจริงแค่ 8px กลับหน้ารายการไม่ได้

ให้ RootLayout รู้จัก route ที่กินเต็มพื้นที่แทน และซ่อน header
เฉพาะตอนอยู่ในห้องแชทบนมือถือ (เต็มจอแบบ LINE)"
```

---

## Task 3: safe area ให้แถบพิมพ์ข้อความ (แก้ P0-2)

**Files:**
- Modify: `frontend/src/components/chat/ChatInputArea.tsx` (5 จุด — ทุก branch ของ state machine)
- Test: `frontend/src/components/chat/ChatInputArea.test.tsx`

**Interfaces:**
- Consumes: (ไม่มี)
- Produces: (ไม่มี — เปลี่ยนแค่ style ภายใน)

- [ ] **Step 1: เขียน test ที่ยังไม่ผ่าน**

สร้าง `frontend/src/components/chat/ChatInputArea.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ChatInputArea } from './ChatInputArea';
import type { Conversation } from '@/types/api';

// LINEMessageInput ลาก quick-reply query ตามมา — ไม่เกี่ยวกับ test นี้
vi.mock('@/components/line/LINEMessageInput', () => ({
  LINEMessageInput: () => <div data-testid="line-input" />,
}));

const noop = async () => {};

function makeConversation(overrides: Partial<Conversation> = {}): Conversation {
  return {
    id: 1,
    channel_type: 'line',
    status: 'closed',
    is_handover: false,
    unread_count: 0,
    message_count: 0,
    created_at: '2026-07-31T00:00:00Z',
    ...overrides,
  } as Conversation;
}

/** ไต่ขึ้นไปหา wrapper ที่มี border-t ซึ่งเป็นตัวที่ต้องกัน safe area */
function inputWrapper(container: HTMLElement) {
  return container.querySelector('.border-t');
}

describe('ChatInputArea safe area', () => {
  it('กัน safe area ขอบล่างในสถานะ closed', () => {
    const { container } = render(
      <ChatInputArea
        conversation={makeConversation({ status: 'closed' })}
        onSendMessage={noop}
        onSendWithMedia={noop}
        onQuickReplySelect={noop}
        isSending={false}
      />
    );

    expect(screen.getByText('This conversation is closed')).toBeInTheDocument();
    expect(inputWrapper(container)?.className).toContain(
      'pb-[env(safe-area-inset-bottom,0px)]'
    );
  });

  it('กัน safe area ขอบล่างในสถานะ bot_active', () => {
    const { container } = render(
      <ChatInputArea
        conversation={makeConversation({ status: 'active', is_handover: false })}
        onSendMessage={noop}
        onSendWithMedia={noop}
        onQuickReplySelect={noop}
        isSending={false}
      />
    );

    expect(inputWrapper(container)?.className).toContain(
      'pb-[env(safe-area-inset-bottom,0px)]'
    );
  });

  it('กัน safe area ขอบล่างในสถานะ line_handover ที่พิมพ์ได้จริง', () => {
    const { container } = render(
      <ChatInputArea
        conversation={makeConversation({ status: 'active', is_handover: true })}
        onSendMessage={noop}
        onSendWithMedia={noop}
        onQuickReplySelect={noop}
        isSending={false}
      />
    );

    expect(screen.getByTestId('line-input')).toBeInTheDocument();
    expect(inputWrapper(container)?.className).toContain(
      'pb-[env(safe-area-inset-bottom,0px)]'
    );
  });
});
```

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

```bash
cd frontend && npm run test -- src/components/chat/ChatInputArea.test.tsx
```

Expected: FAIL ทั้ง 3 เคส เพราะ className ยังไม่มี `pb-[env(...)]`

- [ ] **Step 3: เติม safe area ทั้ง 5 branch**

ใน `frontend/src/components/chat/ChatInputArea.tsx` มี wrapper รูปแบบเดียวกัน 5 จุด (บรรทัด 55, 67, 81, 97 และ default) แทนที่ **ทุกจุด**:

```tsx
<div className="flex-shrink-0 border-t bg-background">
```

ด้วย:

```tsx
<div className="flex-shrink-0 border-t bg-background pb-[env(safe-area-inset-bottom,0px)]">
```

หมายเหตุ: บน iOS ค่า `safe-area-inset-bottom` จะกลายเป็น 0 เองเมื่อคีย์บอร์ดเปิด จึงประกอบกับ `useKeyboardInset` ของ Task 2 ได้โดยไม่เว้นที่ซ้อนกัน

- [ ] **Step 4: รัน test ให้ผ่าน**

```bash
cd frontend && npm run test -- src/components/chat/ChatInputArea.test.tsx
```

Expected: PASS ทั้ง 3 เคส

- [ ] **Step 5: commit**

```bash
git add frontend/src/components/chat/ChatInputArea.tsx frontend/src/components/chat/ChatInputArea.test.tsx
git commit -m "fix(chat): กัน safe area ขอบล่างให้แถบพิมพ์ข้อความ

viewport-fit=cover ถูกตั้งไว้ตั้งแต่แรก แต่หน้าแชทไม่มี safe-area
สักจุด ปุ่มส่งกับช่องพิมพ์จึงจมใต้ home indicator ของ iPhone"
```

---

## Task 4: เลิกเด้งคีย์บอร์ดใส่ตอนเปิดแชท (แก้ P1-4)

**Files:**
- Create: `frontend/src/hooks/useDesktopAutoFocus.ts`
- Test: `frontend/src/hooks/useDesktopAutoFocus.test.tsx`
- Modify: `frontend/src/components/line/LINEMessageInput.tsx:190`
- Modify: `frontend/src/components/telegram/TelegramMessageInput.tsx:148`
- Modify: `frontend/src/components/chat/MessageInput.tsx:85`

**Interfaces:**
- Consumes: (ไม่มี)
- Produces: `useDesktopAutoFocus(ref: RefObject<HTMLElement | null>): void` — focus element ให้เฉพาะจอ ≥768px

- [ ] **Step 1: เขียน test ที่ยังไม่ผ่าน**

สร้าง `frontend/src/hooks/useDesktopAutoFocus.test.tsx`:

```tsx
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render } from '@testing-library/react';
import { useRef } from 'react';
import { useDesktopAutoFocus } from './useDesktopAutoFocus';

/** jsdom ไม่มี matchMedia — stub ให้ตอบตามความกว้างที่กำหนด */
function stubMatchMedia(isDesktop: boolean) {
  Object.defineProperty(window, 'matchMedia', {
    value: vi.fn().mockReturnValue({
      matches: isDesktop,
      media: '(min-width: 768px)',
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    }),
    configurable: true,
    writable: true,
  });
}

function Probe() {
  const ref = useRef<HTMLTextAreaElement>(null);
  useDesktopAutoFocus(ref);
  return <textarea ref={ref} aria-label="probe" />;
}

afterEach(() => {
  Object.defineProperty(window, 'matchMedia', {
    value: undefined,
    configurable: true,
    writable: true,
  });
});

describe('useDesktopAutoFocus', () => {
  it('focus ให้บน desktop', () => {
    stubMatchMedia(true);
    const { getByLabelText } = render(<Probe />);

    expect(document.activeElement).toBe(getByLabelText('probe'));
  });

  it('ไม่ focus บนมือถือ เพื่อไม่ให้คีย์บอร์ดเด้งบังข้อความ', () => {
    stubMatchMedia(false);
    const { getByLabelText } = render(<Probe />);

    expect(document.activeElement).not.toBe(getByLabelText('probe'));
  });
});
```

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

```bash
cd frontend && npm run test -- src/hooks/useDesktopAutoFocus.test.tsx
```

Expected: FAIL — `Failed to resolve import "./useDesktopAutoFocus"`

- [ ] **Step 3: เขียน hook**

สร้าง `frontend/src/hooks/useDesktopAutoFocus.ts`:

```ts
import { useEffect } from 'react';
import type { RefObject } from 'react';

/**
 * Focus ช่องพิมพ์ให้อัตโนมัติเฉพาะบน desktop
 *
 * บนมือถือการ focus ตอน mount ทำให้คีย์บอร์ดเด้งขึ้นมากินครึ่งจอทันที
 * ที่เปิดห้องแชท ก่อนที่ผู้ใช้จะได้อ่านข้อความด้วยซ้ำ
 */
export function useDesktopAutoFocus(ref: RefObject<HTMLElement | null>): void {
  useEffect(() => {
    if (window.matchMedia?.('(min-width: 768px)').matches) {
      ref.current?.focus();
    }
  }, [ref]);
}
```

- [ ] **Step 4: รัน test ให้ผ่าน**

```bash
cd frontend && npm run test -- src/hooks/useDesktopAutoFocus.test.tsx
```

Expected: PASS ทั้ง 2 เคส

- [ ] **Step 5: เปลี่ยน 3 input ให้ใช้ hook แทน `autoFocus`**

ทั้ง 3 ไฟล์มี `textareaRef` อยู่แล้ว ทำเหมือนกันทุกไฟล์:

**`frontend/src/components/line/LINEMessageInput.tsx`** — เพิ่ม import:
```tsx
import { useDesktopAutoFocus } from '@/hooks/useDesktopAutoFocus';
```
เพิ่มการเรียก hook ถัดจาก `const [showAutocomplete, setShowAutocomplete] = useState(false);` (บรรทัด 40):
```tsx
  useDesktopAutoFocus(textareaRef);
```
ลบ `autoFocus` ที่บรรทัด 190 ออก

**`frontend/src/components/telegram/TelegramMessageInput.tsx`** — เพิ่ม import เดียวกัน, เรียก `useDesktopAutoFocus(textareaRef);` หลังการประกาศ ref, ลบ `autoFocus` ที่บรรทัด 148

**`frontend/src/components/chat/MessageInput.tsx`** — เพิ่ม import เดียวกัน, เรียก `useDesktopAutoFocus(textareaRef);` หลังการประกาศ ref, ลบ `autoFocus` ที่บรรทัด 85

> **ห้ามแตะ `frontend/src/components/chat/QuickReplyList.tsx:44`** — `autoFocus` ตรงนั้นอยู่ในช่องค้นหาที่ผู้ใช้กดเปิดเองอย่างตั้งใจ ถูกต้องแล้วบนทุกขนาดจอ

- [ ] **Step 6: รัน test ทั้งชุด + build**

```bash
cd frontend && npm run test && npm run build
```

Expected: ผ่านทั้งหมด

- [ ] **Step 7: commit**

```bash
git add frontend/src/hooks/useDesktopAutoFocus.ts frontend/src/hooks/useDesktopAutoFocus.test.tsx frontend/src/components/line/LINEMessageInput.tsx frontend/src/components/telegram/TelegramMessageInput.tsx frontend/src/components/chat/MessageInput.tsx
git commit -m "fix(chat): ไม่ focus ช่องพิมพ์อัตโนมัติบนมือถือ

เปิดห้องแชทแล้วคีย์บอร์ดเด้งกินครึ่งจอทันทีก่อนได้อ่านข้อความ
คง autoFocus ไว้บน desktop ที่มันมีประโยชน์จริง"
```

---

## Task 5: ทำให้ 2 dialog คุม open จากข้างนอกได้

เตรียมทางให้ Task 6 — Radix ปิดเมนูแล้ว dialog ที่เป็นลูกของ menu item จะถูก unmount ทันที จึงต้องให้ dialog รับ open state จากข้างนอกและ render เป็น sibling ของเมนู

**Files:**
- Modify: `frontend/src/components/chat/ClearContextDialog.tsx`
- Modify: `frontend/src/components/chat/ConfirmPaymentDialog.tsx`
- Test: `frontend/src/components/chat/ClearContextDialog.test.tsx`

**Interfaces:**
- Consumes: (ไม่มี)
- Produces: ทั้ง 2 คอมโพเนนต์รับ props เพิ่ม (optional ทั้งหมด ของเดิมใช้ต่อได้ไม่ต้องแก้):
  - `open?: boolean` — คุม open จากข้างนอก ถ้าไม่ส่งจะใช้ state ในตัวเอง
  - `onOpenChange?: (open: boolean) => void`
  - `showTrigger?: boolean` — default `true`; ส่ง `false` เมื่อจะเปิด dialog จากเมนูข้างนอก

- [ ] **Step 1: เขียน test ที่ยังไม่ผ่าน**

สร้าง `frontend/src/components/chat/ClearContextDialog.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ClearContextDialog } from './ClearContextDialog';

const noop = async () => {};

describe('ClearContextDialog', () => {
  it('ยังทำงานแบบเดิมเมื่อไม่ส่ง prop ใหม่ (มีปุ่มในตัว กดแล้วเปิด)', () => {
    render(<ClearContextDialog onClearContext={noop} isPending={false} />);

    fireEvent.click(screen.getByRole('button', { name: /Reset bot context/i }));

    expect(screen.getByText('Reset bot context?')).toBeInTheDocument();
  });

  it('ซ่อนปุ่ม trigger ได้เมื่อ showTrigger เป็น false', () => {
    render(
      <ClearContextDialog
        onClearContext={noop}
        isPending={false}
        showTrigger={false}
        open={false}
        onOpenChange={() => {}}
      />
    );

    expect(
      screen.queryByRole('button', { name: /Reset bot context/i })
    ).not.toBeInTheDocument();
  });

  it('เปิดจากข้างนอกได้ผ่าน prop open', () => {
    render(
      <ClearContextDialog
        onClearContext={noop}
        isPending={false}
        showTrigger={false}
        open={true}
        onOpenChange={() => {}}
      />
    );

    expect(screen.getByText('Reset bot context?')).toBeInTheDocument();
  });

  it('แจ้ง onOpenChange เมื่อกด Cancel', () => {
    const onOpenChange = vi.fn();
    render(
      <ClearContextDialog
        onClearContext={noop}
        isPending={false}
        showTrigger={false}
        open={true}
        onOpenChange={onOpenChange}
      />
    );

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

    expect(onOpenChange).toHaveBeenCalledWith(false);
  });
});
```

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

```bash
cd frontend && npm run test -- src/components/chat/ClearContextDialog.test.tsx
```

Expected: FAIL — TypeScript/runtime ไม่รู้จัก prop `showTrigger` / `open`

- [ ] **Step 3: แก้ `ClearContextDialog.tsx`**

เขียนทับทั้งไฟล์ `frontend/src/components/chat/ClearContextDialog.tsx`:

```tsx
/**
 * Clear Context Dialog component
 * Extracted from ChatWindow.tsx
 */
import { useState } from 'react';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Loader2, RotateCcw } from 'lucide-react';

interface ClearContextDialogProps {
  onClearContext: () => Promise<void>;
  isPending: boolean;
  /** คุม open จากข้างนอก — ไม่ส่งจะใช้ state ในตัวเอง */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /** ส่ง false เมื่อจะเปิด dialog จากเมนูข้างนอกแทนปุ่มในตัว */
  showTrigger?: boolean;
}

export function ClearContextDialog({
  onClearContext,
  isPending,
  open: controlledOpen,
  onOpenChange,
  showTrigger = true,
}: ClearContextDialogProps) {
  const [internalOpen, setInternalOpen] = useState(false);
  const isControlled = controlledOpen !== undefined;
  const open = isControlled ? controlledOpen : internalOpen;
  const setOpen = isControlled ? (onOpenChange ?? (() => {})) : setInternalOpen;

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      {showTrigger && (
        <AlertDialogTrigger asChild>
          <Button
            variant="outline"
            size="icon"
            disabled={isPending}
            title="Reset bot context"
          >
            {isPending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <RotateCcw className="size-4" />
            )}
          </Button>
        </AlertDialogTrigger>
      )}
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Reset bot context?</AlertDialogTitle>
          <AlertDialogDescription>
            Bot will start with a new context. You can still view the history.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction onClick={onClearContext}>
            Reset Context
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
```

- [ ] **Step 4: แก้ `ConfirmPaymentDialog.tsx` ให้รับ props ชุดเดียวกัน**

ที่ `frontend/src/components/chat/ConfirmPaymentDialog.tsx` แก้ interface (บรรทัด 29-32) เป็น:

```tsx
interface ConfirmPaymentDialogProps {
  onConfirm: (amount?: number) => Promise<ConfirmPaymentResponse>;
  isPending: boolean;
  /** คุม open จากข้างนอก — ไม่ส่งจะใช้ state ในตัวเอง */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /** ส่ง false เมื่อจะเปิด dialog จากเมนูข้างนอกแทนปุ่มในตัว */
  showTrigger?: boolean;
}
```

แก้ signature กับ state (บรรทัด 34-36) จาก:

```tsx
export function ConfirmPaymentDialog({ onConfirm, isPending }: ConfirmPaymentDialogProps) {
  const { toast } = useToast();
  const [open, setOpen] = useState(false);
```

เป็น:

```tsx
export function ConfirmPaymentDialog({
  onConfirm,
  isPending,
  open: controlledOpen,
  onOpenChange,
  showTrigger = true,
}: ConfirmPaymentDialogProps) {
  const { toast } = useToast();
  const [internalOpen, setInternalOpen] = useState(false);
  const isControlled = controlledOpen !== undefined;
  const open = isControlled ? controlledOpen : internalOpen;
  const setOpen = isControlled ? (onOpenChange ?? (() => {})) : setInternalOpen;
```

ตัวแปรชื่อ `open` / `setOpen` เหมือนเดิม โค้ดที่เหลือรวมถึง `setOpen(false)` ในบรรทัด 65 จึงใช้ได้ต่อไม่ต้องแก้

ห่อ `AlertDialogTrigger` (บรรทัด 77-86) ด้วยเงื่อนไข:

```tsx
      {showTrigger && (
        <AlertDialogTrigger asChild>
          <Button variant="outline" size="sm" disabled={isPending}>
            {isPending ? (
              <Loader2 className="size-4 animate-spin sm:mr-1" />
            ) : (
              <BadgeCheck className="size-4 sm:mr-1" />
            )}
            <span className="hidden sm:inline">ยืนยันรับเงิน ✅</span>
          </Button>
        </AlertDialogTrigger>
      )}
```

- [ ] **Step 5: รัน test ให้ผ่าน (รวม test เดิมของ ConfirmPaymentDialog)**

```bash
cd frontend && npm run test -- src/components/chat/ClearContextDialog.test.tsx src/components/chat/ConfirmPaymentDialog.test.tsx
```

Expected: PASS ทั้งหมด — test เดิม 3 เคสของ `ConfirmPaymentDialog` ต้องยังผ่านเพราะมันใช้แบบ uncontrolled ซึ่งเป็น default

- [ ] **Step 6: commit**

```bash
git add frontend/src/components/chat/ClearContextDialog.tsx frontend/src/components/chat/ClearContextDialog.test.tsx frontend/src/components/chat/ConfirmPaymentDialog.tsx
git commit -m "refactor(chat): ให้ 2 dialog ของหัวแชทคุม open จากข้างนอกได้

เตรียมย้ายเข้าเมนู overflow บนมือถือ — Radix จะ unmount dialog ทันที
ที่เมนูปิด ถ้า trigger เป็นลูกของ menu item

props ใหม่เป็น optional ทั้งหมด ที่เรียกใช้เดิมไม่ต้องแก้"
```

---

## Task 6: หัวแชทบนมือถือ — Take Over โชว์ ที่เหลือเข้า ⋮ (แก้ P1-3 + P1-5)

**Files:**
- Create: `frontend/src/components/chat/ChatHeaderActions.tsx`
- Test: `frontend/src/components/chat/ChatHeaderActions.test.tsx`
- Modify: `frontend/src/components/chat/ChatWindow.tsx:79-114`
- Modify: `frontend/src/components/chat/ChatHeader.tsx:47`, `:54`, `:78`, `:93`, `:97-133`

**Interfaces:**
- Consumes: `ClearContextDialog` / `ConfirmPaymentDialog` props `open` / `onOpenChange` / `showTrigger` จาก Task 5
- Produces: `ChatHeaderActions` รับ props:
  ```ts
  interface ChatHeaderActionsProps {
    showConfirmPayment: boolean;
    onConfirmPayment: (amount?: number) => Promise<ConfirmPaymentResponse>;
    isConfirmPaymentPending: boolean;
    showClearContext: boolean;
    onClearContext: () => Promise<void>;
    isClearingContext: boolean;
    onShowInfo?: () => void;
  }
  ```

- [ ] **Step 1: เขียน test ที่ยังไม่ผ่าน**

> **ระวัง — Radix DropdownMenu ใน jsdom:** ต่างจาก `AlertDialogTrigger` (ที่ `ConfirmPaymentDialog.test.tsx` เปิดด้วย `fireEvent.click` ได้) `DropdownMenuTrigger` เปิดด้วย **`pointerdown`** ไม่ใช่ `click` และ Radix เรียก API ที่ jsdom ไม่มี (`hasPointerCapture`, `scrollIntoView`) test ด้านล่างจึงมี `beforeAll` ที่ stub ให้ และใช้ helper `openMenu()` ที่ยิง `pointerDown` แทน `click`
>
> ถ้ารันแล้วยังเปิดเมนูไม่ได้ ให้เปลี่ยนไปใช้ `@testing-library/user-event` (`await userEvent.click(...)` จำลอง pointer event ครบชุด) — เช็คก่อนว่ามันอยู่ใน devDependencies ด้วย `npm ls @testing-library/user-event` ถ้าไม่มีอย่าเพิ่ง install เอง ให้ถามเจ้าของก่อน

สร้าง `frontend/src/components/chat/ChatHeaderActions.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeAll } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { ChatHeaderActions } from './ChatHeaderActions';

vi.mock('@/hooks/use-toast', () => ({ useToast: () => ({ toast: vi.fn() }) }));

// Radix ต้องการ API พวกนี้ซึ่ง jsdom ไม่มี
beforeAll(() => {
  Element.prototype.hasPointerCapture = vi.fn(() => false);
  Element.prototype.setPointerCapture = vi.fn();
  Element.prototype.releasePointerCapture = vi.fn();
  Element.prototype.scrollIntoView = vi.fn();
});

/** DropdownMenuTrigger ของ Radix เปิดด้วย pointerdown ไม่ใช่ click */
function openMenu() {
  fireEvent.pointerDown(
    screen.getByRole('button', { name: 'ตัวเลือกเพิ่มเติม' }),
    { button: 0, ctrlKey: false, pointerType: 'mouse' }
  );
}

const baseProps = {
  showConfirmPayment: true,
  onConfirmPayment: vi.fn().mockResolvedValue({ order_created: false }),
  isConfirmPaymentPending: false,
  showClearContext: true,
  onClearContext: vi.fn().mockResolvedValue(undefined),
  isClearingContext: false,
  onShowInfo: vi.fn(),
};

describe('ChatHeaderActions', () => {
  it('มีปุ่มเมนู overflow ที่มี accessible name', () => {
    render(<ChatHeaderActions {...baseProps} />);

    expect(
      screen.getByRole('button', { name: 'ตัวเลือกเพิ่มเติม' })
    ).toBeInTheDocument();
  });

  it('เมนูมีครบ 3 รายการ: ยืนยันรับเงิน / reset context / ข้อมูลลูกค้า', () => {
    render(<ChatHeaderActions {...baseProps} />);

    openMenu();

    expect(screen.getByRole('menuitem', { name: /ยืนยันรับเงิน/ })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: /Reset context/i })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: /ข้อมูลลูกค้า/ })).toBeInTheDocument();
  });

  it('ซ่อนรายการยืนยันรับเงินเมื่อบอทไม่ได้เปิดตรวจสลิป', () => {
    render(<ChatHeaderActions {...baseProps} showConfirmPayment={false} />);

    openMenu();

    expect(screen.queryByRole('menuitem', { name: /ยืนยันรับเงิน/ })).not.toBeInTheDocument();
  });

  it('ซ่อนรายการ reset context สำหรับ Telegram', () => {
    render(<ChatHeaderActions {...baseProps} showClearContext={false} />);

    openMenu();

    expect(screen.queryByRole('menuitem', { name: /Reset context/i })).not.toBeInTheDocument();
  });

  it('เลือก reset context จากเมนูแล้ว dialog เปิดขึ้นจริง (ไม่โดนเมนูพาปิดไปด้วย)', async () => {
    render(<ChatHeaderActions {...baseProps} />);

    openMenu();
    fireEvent.click(await screen.findByRole('menuitem', { name: /Reset context/i }));

    expect(await screen.findByText('Reset bot context?')).toBeInTheDocument();
  });

  it('เลือกข้อมูลลูกค้าแล้วเรียก onShowInfo', () => {
    const onShowInfo = vi.fn();
    render(<ChatHeaderActions {...baseProps} onShowInfo={onShowInfo} />);

    openMenu();
    fireEvent.click(screen.getByRole('menuitem', { name: /ข้อมูลลูกค้า/ }));

    expect(onShowInfo).toHaveBeenCalled();
  });

  it('ปุ่มเมนูมี touch target 44px บนมือถือ', () => {
    render(<ChatHeaderActions {...baseProps} />);

    const button = screen.getByRole('button', { name: 'ตัวเลือกเพิ่มเติม' });

    expect(button.className).toContain('size-11');
    expect(button.className).toContain('sm:size-9');
  });
});
```

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

```bash
cd frontend && npm run test -- src/components/chat/ChatHeaderActions.test.tsx
```

Expected: FAIL — `Failed to resolve import "./ChatHeaderActions"`

- [ ] **Step 3: สร้าง `ChatHeaderActions.tsx`**

สร้าง `frontend/src/components/chat/ChatHeaderActions.tsx`:

```tsx
/**
 * ปุ่ม action ของหัวแชท
 *
 * มือถือ (<640px): ยุบเป็นเมนู ⋮ เพราะจอ 390px ใส่ปุ่มได้ไม่พอ
 *   จนชื่อลูกค้าเหลือที่ไม่ถึง 40px
 * แท็บเล็ตขึ้นไป (≥640px): เรียงเป็นปุ่มแนวนอนเหมือนเดิม
 *
 * dialog ทั้งสองตัว render เป็น sibling ของเมนู ไม่ใช่ลูกของ menu item
 * เพราะ Radix จะ unmount ลูกของเมนูทันทีที่เมนูปิด
 */
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { BadgeCheck, Info, MoreVertical, RotateCcw } from 'lucide-react';
import { ClearContextDialog } from './ClearContextDialog';
import { ConfirmPaymentDialog } from './ConfirmPaymentDialog';
import type { ConfirmPaymentResponse } from '@/hooks/chat/useConfirmPayment';

interface ChatHeaderActionsProps {
  showConfirmPayment: boolean;
  onConfirmPayment: (amount?: number) => Promise<ConfirmPaymentResponse>;
  isConfirmPaymentPending: boolean;
  showClearContext: boolean;
  onClearContext: () => Promise<void>;
  isClearingContext: boolean;
  onShowInfo?: () => void;
}

type OpenDialog = 'payment' | 'clear' | null;

export function ChatHeaderActions({
  showConfirmPayment,
  onConfirmPayment,
  isConfirmPaymentPending,
  showClearContext,
  onClearContext,
  isClearingContext,
  onShowInfo,
}: ChatHeaderActionsProps) {
  const [openDialog, setOpenDialog] = useState<OpenDialog>(null);

  return (
    <>
      {/* มือถือ: เมนู ⋮ */}
      <div className="sm:hidden">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="outline"
              size="icon"
              className="size-11 sm:size-9"
              aria-label="ตัวเลือกเพิ่มเติม"
            >
              <MoreVertical className="size-5" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {showConfirmPayment && (
              <DropdownMenuItem onSelect={() => setOpenDialog('payment')}>
                <BadgeCheck className="size-4 mr-2" />
                ยืนยันรับเงิน
              </DropdownMenuItem>
            )}
            {showClearContext && (
              <DropdownMenuItem onSelect={() => setOpenDialog('clear')}>
                <RotateCcw className="size-4 mr-2" />
                Reset context
              </DropdownMenuItem>
            )}
            {onShowInfo && (
              <DropdownMenuItem onSelect={onShowInfo}>
                <Info className="size-4 mr-2" />
                ข้อมูลลูกค้า
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      {/* แท็บเล็ตขึ้นไป: ปุ่มแนวนอนเหมือนเดิม */}
      <div className="hidden sm:flex items-center gap-2">
        {showConfirmPayment && (
          <ConfirmPaymentDialog
            onConfirm={onConfirmPayment}
            isPending={isConfirmPaymentPending}
          />
        )}
        {showClearContext && (
          <ClearContextDialog
            onClearContext={onClearContext}
            isPending={isClearingContext}
          />
        )}
      </div>

      {/* dialog ที่เมนูมือถือสั่งเปิด — เป็น sibling ของเมนู ไม่ใช่ลูก */}
      {showConfirmPayment && (
        <ConfirmPaymentDialog
          onConfirm={onConfirmPayment}
          isPending={isConfirmPaymentPending}
          showTrigger={false}
          open={openDialog === 'payment'}
          onOpenChange={(next) => setOpenDialog(next ? 'payment' : null)}
        />
      )}
      {showClearContext && (
        <ClearContextDialog
          onClearContext={onClearContext}
          isPending={isClearingContext}
          showTrigger={false}
          open={openDialog === 'clear'}
          onOpenChange={(next) => setOpenDialog(next ? 'clear' : null)}
        />
      )}
    </>
  );
}
```

- [ ] **Step 4: รัน test ให้ผ่าน**

```bash
cd frontend && npm run test -- src/components/chat/ChatHeaderActions.test.tsx
```

Expected: PASS ทั้ง 7 เคส

- [ ] **Step 5: ให้ `ChatWindow` ใช้คอมโพเนนต์ใหม่**

ที่ `frontend/src/components/chat/ChatWindow.tsx` แทน import บรรทัด 18-19:

```tsx
import { ClearContextDialog } from './ClearContextDialog';
import { ConfirmPaymentDialog } from './ConfirmPaymentDialog';
```

ด้วย:

```tsx
import { ChatHeaderActions } from './ChatHeaderActions';
```

แทนบล็อกบรรทัด 79-101 (ตั้งแต่คอมเมนต์ `// Clear context button...` ถึงปิด `);` ของ `headerActions`) ด้วย:

```tsx
  // Manual payment confirm - LINE conversations of slip-verification-enabled bots only
  const showConfirmPayment = isLINE && Boolean(botSettings?.slip_verification_enabled);
  const headerActions = (
    <ChatHeaderActions
      showConfirmPayment={showConfirmPayment}
      onConfirmPayment={(amount) =>
        confirmPayment.mutateAsync({ conversationId: conversation.id, amount })
      }
      isConfirmPaymentPending={confirmPayment.isPending}
      // Telegram ไม่รองรับการล้าง context
      showClearContext={!isTelegram}
      onClearContext={handleClearContext}
      isClearingContext={isClearingContext}
      onShowInfo={onShowInfo}
    />
  );
```

- [ ] **Step 6: ปรับ `ChatHeader` ให้ touch target ได้ 44px และไม่โชว์ปุ่มซ้ำกับเมนู**

ที่ `frontend/src/components/chat/ChatHeader.tsx` แก้ 5 จุด:

**บรรทัด 54** (ปุ่ม back — 44px บนมือถือ):
```diff
- className="md:hidden size-10 min-h-[40px] min-w-[40px] flex-shrink-0 border-2"
+ className="md:hidden size-11 min-h-[44px] min-w-[44px] flex-shrink-0 border-2"
```

**บรรทัด 78** (ชื่อลูกค้า — พอปุ่มยุบเข้าเมนูแล้วไม่ต้องบีบเหลือ 120px):
```diff
- <h2 className="font-semibold text-sm sm:text-base truncate max-w-[120px] sm:max-w-none">
+ <h2 className="font-semibold text-sm sm:text-base truncate">
```

**บรรทัด 93** (ระยะห่างระหว่างปุ่มขั้นต่ำ 8px):
```diff
- <div className="flex items-center gap-1 sm:gap-2 flex-shrink-0">
+ <div className="flex items-center gap-2 flex-shrink-0">
```

**บรรทัด 98-117** (ปุ่ม Take Over — ซ่อนข้อความบนมือถือ ให้เหลือไอคอน 44px):
```tsx
          <Button
            variant={conversation.is_handover ? 'default' : 'outline'}
            size="sm"
            className="size-11 p-0 sm:size-auto sm:px-3 sm:py-2"
            onClick={onToggleHandover}
            disabled={isToggleLoading}
            aria-label={conversation.is_handover ? 'Enable Bot' : 'Take Over'}
          >
            {isToggleLoading ? (
              <Loader2 className="size-4 animate-spin" />
            ) : conversation.is_handover ? (
              <>
                <Bot className="size-4 sm:mr-1" />
                <span className="hidden sm:inline">Enable Bot</span>
              </>
            ) : (
              <>
                <Headphones className="size-4 sm:mr-1" />
                <span className="hidden sm:inline">Take Over</span>
              </>
            )}
          </Button>
```

**บรรทัด 124-133** (ปุ่ม Info — ต่ำกว่า 640px ย้ายไปอยู่ในเมนู ⋮ แล้ว จึงแสดงเฉพาะ 640–1280px):
```diff
-          <Button
-            variant="outline"
-            size="icon"
-            className="xl:hidden"
-            onClick={onShowInfo}
-          >
+          <Button
+            variant="outline"
+            size="icon"
+            className="hidden sm:inline-flex xl:hidden"
+            onClick={onShowInfo}
+            aria-label="ข้อมูลลูกค้า"
+          >
```

- [ ] **Step 7: รัน test ทั้งชุด + build + lint**

```bash
cd frontend && npm run test && npm run build && npm run lint
```

Expected: ผ่านทั้งหมด

- [ ] **Step 8: commit**

```bash
git add frontend/src/components/chat/ChatHeaderActions.tsx frontend/src/components/chat/ChatHeaderActions.test.tsx frontend/src/components/chat/ChatWindow.tsx frontend/src/components/chat/ChatHeader.tsx
git commit -m "fix(chat): ยุบปุ่มหัวแชทเข้าเมนู overflow บนมือถือ

จอ 390px มี 5 อย่างเบียดกันจนชื่อลูกค้าเหลือที่ราว 36px
Take Over อยู่เป็นไอคอน 44px ที่เหลือเข้าเมนู ⋮
ตั้งแต่ 640px ขึ้นไปหน้าตาเหมือนเดิมทุกอย่าง"
```

---

## Task 7: ตรวจตำแหน่งจริงด้วยเบราว์เซอร์ + ส่งให้เจ้าของทดสอบ

vitest ตรวจ layout จริงไม่ได้ (jsdom ไม่มี layout engine) — task นี้คือการพิสูจน์ box model ด้วยเบราว์เซอร์จริง

**Files:**
- Create: `<scratchpad>/repro-after.html` — **ไฟล์ชั่วคราว ห้าม commit เข้า repo**

**Interfaces:**
- Consumes: ผลลัพธ์ทุก task ก่อนหน้า
- Produces: (ไม่มี — เป็นขั้นตรวจสอบ)

- [ ] **Step 1: สร้างไฟล์จำลอง box model หลังแก้**

สร้าง `repro-after.html` ในโฟลเดอร์ scratchpad ของ session (ไม่ใช่ในโปรเจกต์) — คัดลอก CSS จากโค้ดที่แก้แล้วให้ตรงกัน:

```html
<!doctype html>
<html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui}
/* RootLayout หลังแก้ */
.root{display:flex;height:100dvh;background:#fff}
.col{display:flex;flex:1 1 0%;flex-direction:column;overflow:hidden}
/* อยู่ในห้องแชทบนมือถือ = ไม่ render header (hideMobileHeader) */
main{flex:1 1 0%;min-height:0;overflow:hidden}
/* ChatPage หลังแก้: h-full ไม่มี negative margin */
.chat{display:flex;height:100%;overflow:hidden;background:#fff}
.panel{flex:1;display:flex;flex-direction:column;min-width:0}
.chathdr{flex-shrink:0;display:flex;align-items:center;justify-content:space-between;padding:8px;border-bottom:1px solid #e5e5e5;background:#fff}
.back{width:44px;height:44px;border:2px solid #333;border-radius:8px;display:flex;align-items:center;justify-content:center;background:#ffe9e9;font-weight:700}
.msgs{flex:1;min-height:0;overflow:auto;padding:16px;background:#f6f6f6}
/* ChatInputArea หลังแก้ — จำลอง home indicator 34px ด้วยตัวแปร */
.input{flex-shrink:0;border-top:1px solid #e5e5e5;background:#e9f7ee;padding:8px;
       padding-bottom:calc(8px + var(--safe-bottom, 0px));display:flex;gap:8px;align-items:center}
.ta{flex:1;padding:8px 16px;background:#f1f1f1;border-radius:16px}
.send{width:44px;height:44px;border-radius:999px;background:#06C755}
.homebar{position:fixed;left:0;right:0;bottom:0;height:34px;background:rgba(255,0,0,.25);pointer-events:none;z-index:100}
</style></head>
<body style="--safe-bottom:34px">
<div class="root"><div class="col"><main>
  <div class="chat"><div class="panel">
    <div class="chathdr">
      <div style="display:flex;gap:8px;align-items:center">
        <div class="back">←</div>
        <div style="width:32px;height:32px;border-radius:999px;background:#ddd"></div>
        <div><div style="font-weight:600;font-size:14px">คุณสมชาย ใจดี</div>
             <div style="font-size:12px;color:#777">LINE · 42 ข้อความ</div></div>
      </div>
      <div style="display:flex;gap:8px">
        <div style="width:44px;height:44px;border:1px solid #ccc;border-radius:8px"></div>
        <div style="width:44px;height:44px;border:1px solid #ccc;border-radius:8px"></div>
      </div>
    </div>
    <div class="msgs" id="msgs"></div>
    <div class="input"><div style="width:44px;height:44px"></div>
      <div class="ta">พิมพ์ข้อความ...</div><div class="send"></div></div>
  </div></div>
</main></div></div>
<div class="homebar"></div>
<script>
const m=document.getElementById('msgs');
for(let i=0;i<20;i++){m.insertAdjacentHTML('beforeend',
  '<div style="margin:8px 0;padding:8px 12px;background:#fff;border-radius:8px;display:inline-block">ข้อความที่ '+i+'</div><br>')}
m.scrollTop=m.scrollHeight;
window.__check = () => {
  const back=document.querySelector('.back').getBoundingClientRect();
  const chat=document.querySelector('.chat').getBoundingClientRect();
  const send=document.querySelector('.send').getBoundingClientRect();
  const hit=document.elementFromPoint(back.x+back.width/2, back.y+back.height/2);
  const SAFE=34;
  return {
    backTappable: hit && hit.classList.contains('back'),
    backSize: [Math.round(back.width), Math.round(back.height)],
    chatTop: Math.round(chat.top),
    sendBottom: Math.round(send.bottom),
    sendClearsHomeBar: send.bottom <= innerHeight - SAFE,
    vh: innerHeight,
  };
};
</script></body></html>
```

- [ ] **Step 2: เปิดที่ขนาด iPhone แล้วอ่านค่า**

```bash
terminal-browser open "<scratchpad>/repro-after.html" --split right --size 0.45
terminal-browser action -- set viewport 390 844
terminal-browser action -- eval "JSON.stringify(window.__check())"
```

Expected — ทุกค่าต้องตรงตามนี้:
- `backTappable: true` ← ก่อนแก้ได้ `"hdr"` แปลว่าปุ่มโดน header ทับ
- `backSize: [44, 44]`
- `chatTop: 0` ← ก่อนแก้ได้ `16`
- `sendClearsHomeBar: true` ← ก่อนแก้ `sendBottom` = 844 = ชนขอบล่างพอดี

ถ้าค่าไหนไม่ตรง **ห้ามข้ามไป** — ย้อนกลับไปหาว่า CSS ในโค้ดจริงไม่ตรงกับที่จำลองตรงไหน

- [ ] **Step 3: ปิดเบราว์เซอร์**

```bash
terminal-browser action -- close --all
```

ถ้า `terminal-browser ls --all` ยังเห็น browser ค้าง ให้ `kill <pid>` จาก `terminal-browser ls --all --json`

- [ ] **Step 4: ตรวจว่า desktop ไม่เปลี่ยน**

```bash
cd frontend && npm run dev
```

เปิดหน้าแชทที่ความกว้าง ≥1280px เทียบกับ `git stash` แล้วดูของเดิม — ต้องเหมือนกันทุกอย่าง: 3 คอลัมน์, padding รอบนอก, ปุ่มในหัวแชทเรียงแนวนอนพร้อมข้อความเต็ม

- [ ] **Step 5: รัน gate ทั้งหมดก่อนปิดงาน**

```bash
cd frontend && npm run test && npm run build && npm run lint && npx tsc --noEmit
```

Expected: ผ่านทุกคำสั่ง

- [ ] **Step 6: ส่งให้เจ้าของทดสอบบนเครื่องจริง**

**ยังห้ามเคลมว่างานเสร็จ** — 2 ข้อนี้จำลองบน desktop ไม่ได้ ต้องให้เจ้าของยืนยัน:

1. **iPhone:** เปิดห้องแชท → ปุ่มส่งกับช่องพิมพ์ต้องไม่จมใต้แถบ home indicator
2. **iPhone + Android:** แตะช่องพิมพ์ให้คีย์บอร์ดขึ้น → ช่องพิมพ์กับปุ่มส่งต้องยังเห็นอยู่เหนือคีย์บอร์ด

ถ้าข้อ 2 ยังเพี้ยนบน iOS ทางแก้ถัดไปตามลำดับ (ระบุไว้ในหัวข้อความเสี่ยงของ spec):
- เติม `interactive-widget=resizes-content` ใน viewport meta ที่ `frontend/index.html:6`
- ถ้ายังไม่พอ บังคับ `window.scrollTo(0, 0)` ตอน textarea blur

---

## Self-Review

**Spec coverage:**

| หัวข้อใน spec | Task ที่ทำ |
|---|---|
| P0-1 layout ทับกัน (RootLayout + ChatPage) | Task 2 |
| P0-2 safe area แถบพิมพ์ | Task 3 |
| P1-3 header ยัดปุ่มเกิน | Task 6 |
| P1-4 autoFocus | Task 4 |
| P1-5 touch target 44px / gap 8px | Task 6 (ปุ่ม back, Take Over, Info, ⋮) |
| P1-6 คีย์บอร์ด visualViewport | Task 1 (hook) + Task 2 (นำไปใช้) |
| safe area ซ้าย/ขวาแนวนอน | Task 2 Step 5 |
| dialog คุม open จากข้างนอก | Task 5 |
| desktop ไม่เปลี่ยน | Global Constraints + Task 7 Step 4 |
| เกณฑ์ตรวจอัตโนมัติ | Task 7 Step 2 + Step 5 |
| เกณฑ์ตรวจโดยเจ้าของ | Task 7 Step 6 |
| นอกขอบเขต: ปุ่ม Reset All Contexts | ไม่มี task — ตรงตามที่ตกลง |

**Type consistency:** `useKeyboardInset(): number` (Task 1 → ใช้ Task 2), `useDesktopAutoFocus(ref)` (Task 4), props `open` / `onOpenChange` / `showTrigger` (Task 5 → ใช้ Task 6), `ChatHeaderActionsProps` (Task 6) — ชื่อและ type ตรงกันทุกที่ที่อ้างถึง

**Placeholder scan:** ไม่มี TBD/TODO ทุก step ที่เป็นโค้ดมี code block จริง
