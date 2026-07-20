# Dashboard Mobile UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ทำให้หน้าแดชบอร์ด (และโครง layout มือถือ) ใช้งานบนมือถือได้ดี — แก้ gap ซ้อน 56px, หัวกราฟเบียด, ตารางออเดอร์ต้องปาดแนวนอน, ข้อมูลการ์ดสถิติโดนตัด, header มือถือไม่มีชื่อหน้า

**Architecture:** แก้เฉพาะ frontend (React 19 + Tailwind v4) เป็น surgical CSS/markup changes ใน 5 ไฟล์ + เพิ่ม mobile card list ใน RecentOrdersPreview (pattern `md:hidden` / `hidden md:block`) + เพิ่ม page-title map ใน Header ไม่มีการแตะ backend หรือ API ใด ๆ

**Tech Stack:** React 19, Tailwind CSS v4 (theme ผ่าน `@theme` ใน `frontend/src/index.css` — ไม่มี tailwind.config), Vitest + @testing-library/react (jsdom), react-router

## Global Constraints

- แก้เฉพาะใต้ `frontend/` เท่านั้น — ห้ามแตะ backend
- ทุกคำสั่ง npm รันจากไดเรกทอรี `frontend/` (`cd frontend` ก่อน)
- ห้าม "ปรับปรุง" โค้ดข้างเคียงที่ไม่เกี่ยวกับ task (per CLAUDE.md Surgical Changes)
- Breakpoint convention ของโปรเจกต์: mobile = ต่ำกว่า `md` (768px); ใช้ `md:hidden` / `hidden md:block` แยก layout
- ห้ามใช้ emoji เป็น icon — ใช้ lucide-react SVG เท่านั้น
- `Metric` เป็น shared component (ใช้ใน SlipsPage, VipManagementPage, KnowledgeBasePage ด้วย) — การแก้ Task 5 กระทบทุกหน้า ถือว่าตั้งใจ (ทุกหน้าได้ประโยชน์เหมือนกัน)
- Commit แยกตาม task, ข้อความ commit ตาม convention repo (`fix(...)` / `feat(...)` + คำอธิบายไทย)

---

### Task 1: แก้ gap ซ้อน 56px ใต้ header มือถือ (RootLayout)

**Context:** commit `50803f5` เพิ่ม `pt-14` ให้ `<main>` โดยเข้าใจว่า mobile header เป็น overlay แต่จริง ๆ `<header>` (ใน `Header.tsx`) เป็น sibling ของ `<main>` ใน flex column ที่ไม่ scroll — header กินความสูง 56px ของตัวเองอยู่แล้ว `pt-14` จึงเป็นช่องว่างเปล่า 56px ซ้อนเพิ่ม

**Files:**
- Modify: `frontend/src/components/layout/RootLayout.tsx:18`

**Interfaces:**
- Consumes: —
- Produces: — (class-only change ไม่มี interface)

- [ ] **Step 1: แก้ className ของ main**

เปลี่ยนบรรทัด 18 จาก:

```tsx
        <main className="flex-1 overflow-auto p-4 md:p-6 pt-14 md:pt-6">
```

เป็น:

```tsx
        <main className="flex-1 overflow-auto p-4 md:p-6">
```

(ห้ามแตะ `sticky top-0` ใน Header.tsx — ไม่มีผลเสียและอยู่นอกขอบเขต)

- [ ] **Step 2: ตรวจว่า build + test เดิมยังผ่าน**

Run: `cd frontend && npm run build && npx vitest run`
Expected: build สำเร็จ, test ผ่านทั้งหมด (ไม่มี test ใดอ้าง class นี้)

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/layout/RootLayout.tsx
git commit -m "fix(layout): ลบ pt-14 ซ้ำซ้อน — header มือถือไม่ใช่ overlay ทำให้เกิด gap เปล่า 56px"
```

---

### Task 2: แสดงชื่อหน้าปัจจุบันใน header มือถือ

**Context:** `Header.tsx` (แสดงเฉพาะจอ < md) มีแค่ปุ่มแฮมเบอร์เกอร์ + `<div className="flex-1" />` placeholder เปล่า — เสียแถบ 56px โดยไม่บอกผู้ใช้ว่าอยู่หน้าไหน

**Files:**
- Modify: `frontend/src/components/layout/Header.tsx`
- Test: `frontend/src/components/layout/Header.test.tsx` (สร้างใหม่)

**Interfaces:**
- Consumes: `useLocation` จาก `react-router`
- Produces: ฟังก์ชันภายในไฟล์ `pageTitle(pathname: string): string` (ไม่ export — ใช้ใน Header เท่านั้น)

- [ ] **Step 1: เขียน failing test**

สร้าง `frontend/src/components/layout/Header.test.tsx`:

```tsx
import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { Header } from './Header';

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Header />
    </MemoryRouter>,
  );
}

describe('Header page title', () => {
  it('แสดง แดชบอร์ด เมื่ออยู่ /dashboard', () => {
    renderAt('/dashboard');
    expect(screen.getByText('แดชบอร์ด')).toBeInTheDocument();
  });

  it('แสดง Quick Replies เมื่ออยู่ route ซ้อน /settings/quick-replies (longest prefix ชนะ)', () => {
    renderAt('/settings/quick-replies');
    expect(screen.getByText('Quick Replies')).toBeInTheDocument();
    expect(screen.queryByText('ตั้งค่า')).not.toBeInTheDocument();
  });

  it('แสดงชื่อหน้าแม้เป็น sub-route เช่น /bots/5/settings', () => {
    renderAt('/bots/5/settings');
    expect(screen.getByText('การเชื่อมต่อ')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

Run: `cd frontend && npx vitest run src/components/layout/Header.test.tsx`
Expected: FAIL — หา text 'แดชบอร์ด' ไม่เจอ (ตอนนี้ header ยังไม่มี title)

- [ ] **Step 3: Implement ใน Header.tsx**

แก้ `frontend/src/components/layout/Header.tsx` ทั้งไฟล์เป็น:

```tsx
import { useLocation } from 'react-router';
import { useUIStore } from '@/stores/uiStore';
import { Button } from '@/components/ui/button';
import {
  Sheet,
  SheetContent,
  SheetTrigger,
  SheetTitle,
  SheetDescription,
} from '@/components/ui/sheet';
import * as VisuallyHidden from '@radix-ui/react-visually-hidden';
import { MobileNav } from './MobileNav';
import { Menu } from 'lucide-react';

const PAGE_TITLES: Record<string, string> = {
  '/dashboard': 'แดชบอร์ด',
  '/orders': 'ออเดอร์',
  '/vip-customers': 'ลูกค้า VIP',
  '/slips': 'รายการสลิป',
  '/bots': 'การเชื่อมต่อ',
  '/connections': 'การเชื่อมต่อ',
  '/knowledge-base': 'ฐานความรู้',
  '/chat': 'แชท',
  '/settings/quick-replies': 'Quick Replies',
  '/settings': 'ตั้งค่า',
  '/telegram': 'Telegram',
  '/team': 'จัดการทีม',
};

function pageTitle(pathname: string): string {
  const match = Object.keys(PAGE_TITLES)
    .sort((a, b) => b.length - a.length)
    .find((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
  return match ? PAGE_TITLES[match] : '';
}

export function Header() {
  const { sidebarOpen, setSidebarOpen } = useUIStore();
  const { pathname } = useLocation();

  return (
    <header className="sticky top-0 z-40 flex h-14 items-center border-b bg-background/80 backdrop-blur-sm supports-[backdrop-filter]:bg-background/60 px-4 md:hidden">
      {/* Mobile menu button */}
      <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
        <SheetTrigger asChild>
          <Button variant="ghost" size="icon" className="size-9">
            <Menu className="size-5" strokeWidth={1.5} />
            <span className="sr-only">เปิดเมนู</span>
          </Button>
        </SheetTrigger>
        <SheetContent side="left" className="w-72 p-0">
          <VisuallyHidden.Root>
            <SheetTitle>Navigation Menu</SheetTitle>
            <SheetDescription>Navigate to different sections of the application</SheetDescription>
          </VisuallyHidden.Root>
          <MobileNav onNavigate={() => setSidebarOpen(false)} />
        </SheetContent>
      </Sheet>

      {/* Current page title */}
      <p className="ml-2 flex-1 truncate text-sm font-semibold">{pageTitle(pathname)}</p>
    </header>
  );
}
```

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `cd frontend && npx vitest run src/components/layout/Header.test.tsx`
Expected: PASS ทั้ง 3 เคส

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/layout/Header.tsx frontend/src/components/layout/Header.test.tsx
git commit -m "feat(layout): แสดงชื่อหน้าปัจจุบันบน header มือถือ"
```

---

### Task 3: DualAxisChart — หัวการ์ด wrap ได้ + ลด padding/แกน Y บนจอเล็ก

**Context:** หัวการ์ดบังคับ title + Tabs (7/14/30 วัน) แถวเดียว → เบียดบนจอ ~390px; การ์ด `p-6` (48px) + แกน Y สองข้าง ข้างละ 50px กินพื้นที่วาดกราฟจนเหลือ ~200px

**Files:**
- Modify: `frontend/src/components/dashboard/DualAxisChart.tsx:83,95,96,142,152`

**Interfaces:**
- Consumes: —
- Produces: — (props เดิมทุกอย่าง)

- [ ] **Step 1: แก้ 3 จุดใน DualAxisChart.tsx**

จุดที่ 1 — empty state (บรรทัด 83) จาก:

```tsx
      <div className="rounded-xl border bg-card p-6 shadow-sm">
```

เป็น:

```tsx
      <div className="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
```

จุดที่ 2 — การ์ดหลัก + แถวหัวการ์ด (บรรทัด 95–96) จาก:

```tsx
    <div className="rounded-xl border bg-card p-6 shadow-sm">
      <div className="mb-4 flex items-center justify-between gap-2">
```

เป็น:

```tsx
    <div className="rounded-xl border bg-card p-4 sm:p-6 shadow-sm">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
```

จุดที่ 3 — แกน Y ทั้งสองข้าง: เปลี่ยน `width={50}` เป็น `width={40}` ทั้งใน `<YAxis yAxisId="left" ...>` (บรรทัด ~142) และ `<YAxis yAxisId="right" ...>` (บรรทัด ~152) — tick เป็นข้อความสั้น (`฿12k` / `฿250`) ที่ fontSize 11 พอดีใน 40px

- [ ] **Step 2: ตรวจ build + test**

Run: `cd frontend && npm run build && npx vitest run`
Expected: ผ่านทั้งหมด

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/dashboard/DualAxisChart.tsx
git commit -m "fix(dashboard): กราฟยอดขาย responsive บนมือถือ — หัวการ์ด wrap, ลด padding และความกว้างแกน Y"
```

---

### Task 4: RecentOrdersPreview — card list บนมือถือ, ตารางเฉพาะจอ ≥ md

**Context:** ตาราง 5 คอลัมน์ + `whitespace-nowrap` บังคับผู้ใช้มือถือปาดแนวนอนเพื่ออ่านออเดอร์แค่ 5 แถว

**Files:**
- Modify: `frontend/src/components/dashboard/RecentOrdersPreview.tsx`
- Test: `frontend/src/components/dashboard/RecentOrdersPreview.test.tsx` (สร้างใหม่)

**Interfaces:**
- Consumes: `useOrders({ per_page: 5 })` จาก `@/hooks/useOrders` (เดิม), type `Order` จาก `@/types/api` (fields ที่ใช้: `id`, `created_at`, `customer_profile?.display_name`, `items[].product_name`, `items[].quantity`, `total_amount`, `status`)
- Produces: — (component ชื่อ/props เดิม)

- [ ] **Step 1: เขียน failing test**

สร้าง `frontend/src/components/dashboard/RecentOrdersPreview.test.tsx`:

```tsx
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { RecentOrdersPreview } from './RecentOrdersPreview';

vi.mock('@/hooks/useOrders', () => ({
  useOrders: () => ({
    isLoading: false,
    data: {
      orders: [
        {
          id: 1,
          created_at: '2026-07-19T10:00:00Z',
          customer_profile: { display_name: 'คุณเอ' },
          items: [{ product_name: 'BM50', quantity: 1 }],
          total_amount: 450,
          status: 'completed',
        },
      ],
    },
  }),
}));

describe('RecentOrdersPreview responsive layouts', () => {
  it('แสดงออเดอร์ทั้งใน card list (มือถือ) และตาราง (เดสก์ท็อป)', () => {
    render(
      <MemoryRouter>
        <RecentOrdersPreview />
      </MemoryRouter>,
    );
    // ชื่อลูกค้าต้องปรากฏ 2 ที่: mobile card + desktop table
    expect(screen.getAllByText('คุณเอ')).toHaveLength(2);
    expect(screen.getAllByText('สำเร็จ')).toHaveLength(2);
  });
});
```

- [ ] **Step 2: รัน test ให้เห็นว่า fail**

Run: `cd frontend && npx vitest run src/components/dashboard/RecentOrdersPreview.test.tsx`
Expected: FAIL — `getAllByText('คุณเอ')` เจอแค่ 1 (มีแต่ตาราง)

- [ ] **Step 3: เพิ่ม mobile card list + ซ่อนตารางบนมือถือ**

ใน `frontend/src/components/dashboard/RecentOrdersPreview.tsx` แก้ส่วน return หลัก (หลัง `if (!orders.length)`) — เปลี่ยน wrapper ของตารางจาก:

```tsx
      <div className="overflow-x-auto rounded-lg border">
```

เป็น:

```tsx
      <div className="hidden overflow-x-auto rounded-lg border md:block">
```

และแทรก mobile card list **ก่อน** div ตารางนั้น (หลัง `<h3 ...>ออเดอร์ล่าสุด</h3>`):

```tsx
      {/* Mobile: card list */}
      <div className="space-y-2 md:hidden">
        {orders.map((order: Order) => (
          <div key={order.id} className="rounded-lg border p-3">
            <div className="flex items-center justify-between gap-2">
              <span className="min-w-0 truncate text-sm font-medium">
                {order.customer_profile?.display_name ?? '-'}
              </span>
              <span className="shrink-0 text-sm font-semibold">
                {formatBaht(order.total_amount)}
              </span>
            </div>
            <div className="mt-1 flex items-center justify-between gap-2">
              <span className="min-w-0 truncate text-xs text-muted-foreground">
                {new Date(order.created_at).toLocaleDateString('th-TH', {
                  day: '2-digit',
                  month: 'short',
                })}
                {' · '}
                {order.items.length > 0
                  ? order.items.map((item) => `${item.product_name} x${item.quantity}`).join(', ')
                  : '-'}
              </span>
              <Badge variant={STATUS_VARIANTS[order.status] || 'secondary'}>
                {STATUS_LABELS[order.status] || order.status}
              </Badge>
            </div>
          </div>
        ))}
      </div>
```

(ใช้ `formatBaht`, `Badge`, `STATUS_VARIANTS`, `STATUS_LABELS`, `Order` ที่ import อยู่แล้วในไฟล์ — ไม่ต้องเพิ่ม import)

- [ ] **Step 4: รัน test ให้ผ่าน**

Run: `cd frontend && npx vitest run src/components/dashboard/RecentOrdersPreview.test.tsx`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/dashboard/RecentOrdersPreview.tsx frontend/src/components/dashboard/RecentOrdersPreview.test.tsx
git commit -m "feat(dashboard): ออเดอร์ล่าสุดแสดงเป็น card list บนมือถือ แทนตารางที่ต้องปาดแนวนอน"
```

---

### Task 5: Metric — ตัวเลขไม่ล้นการ์ด + hint ไม่โดนตัดทิ้ง

**Context:** ในกริด 2 คอลัมน์บนมือถือ การ์ดกว้าง ~140–170px: ค่า `text-2xl` (เช่น ฿123,456) เสี่ยงล้นขอบ และ hint มี `truncate` ทำให้ข้อมูลสำคัญ (ค่า API รายเดือน, ยอด VIP) โดนตัดหาย
**หมายเหตุ:** `Metric` ใช้ร่วมใน SlipsPage / VipManagementPage / KnowledgeBasePage ด้วย — การเปลี่ยนนี้กระทบทุกหน้า (ตั้งใจ)

**Files:**
- Modify: `frontend/src/components/common/Metric.tsx:32,41`

**Interfaces:**
- Consumes: —
- Produces: — (props เดิม)

- [ ] **Step 1: แก้ 2 บรรทัดใน Metric.tsx**

บรรทัด 32 จาก:

```tsx
      <p className="mt-2 text-2xl font-semibold tabular-nums leading-none">{value}</p>
```

เป็น:

```tsx
      <p className="mt-2 text-xl sm:text-2xl font-semibold tabular-nums leading-none">{value}</p>
```

บรรทัด 41 จาก:

```tsx
          {hint && <span className="truncate">{hint}</span>}
```

เป็น:

```tsx
          {hint && <span className="min-w-0">{hint}</span>}
```

(เอา `truncate` ออกให้ข้อความ hint ขึ้นบรรทัดใหม่ได้แทนการโดนตัด; `min-w-0` ให้ flex item หดและ wrap ภายในได้)

- [ ] **Step 2: ตรวจ build + test ทั้งหมด**

Run: `cd frontend && npm run build && npx vitest run`
Expected: ผ่านทั้งหมด

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/common/Metric.tsx
git commit -m "fix(ui): การ์ดสถิติบนมือถือ — ลดขนาดตัวเลขกันล้น และให้ hint ขึ้นบรรทัดใหม่แทนโดนตัด"
```

---

### Task 6: Final verification (ทั้ง suite + visual check)

**Files:** — (ไม่แก้ไฟล์)

**Interfaces:** —

- [ ] **Step 1: รัน test + build + lint ทั้งหมด**

Run:

```bash
cd frontend && npx vitest run && npm run build && npm run lint
```

Expected: ผ่านทั้งหมด 0 error (ถ้าไม่มี script `lint` ให้ข้ามส่วน lint และรายงานว่าข้าม)

- [ ] **Step 2: Visual check บน viewport มือถือ (390×844)**

ถ้ารัน dev server ได้ (`cd frontend && npm run dev` + backend):
เปิดหน้า `/dashboard` ที่ viewport 390×844 (Playwright browser_resize หรือ Chrome DevTools) แล้วตรวจ checklist:

1. ไม่มีช่องว่างเปล่า ~56px ระหว่าง header มือถือกับหัวข้อ "แดชบอร์ด"
2. header มือถือแสดงคำว่า "แดชบอร์ด" ข้างปุ่มเมนู
3. หัวกราฟ "ยอดขาย vs ค่า API" กับปุ่ม 7/14/30 วัน ไม่เบียด/ไม่ล้น (wrap ลงบรรทัดได้)
4. ออเดอร์ล่าสุดเป็น card list — ไม่มี scrollbar แนวนอน
5. การ์ดสถิติ 2 คอลัมน์: ตัวเลขไม่ล้นขอบ, hint อ่านได้ครบ (ขึ้นบรรทัดใหม่)
6. ไม่มี element ใดทำให้ทั้งหน้า scroll แนวนอนได้

ถ้ารัน dev server ไม่ได้ (backend ไม่พร้อม): บันทึกว่า visual check รอเจ้าของตรวจบน prod หลัง deploy แล้วรายงานตามจริง — ห้าม claim ว่าตรวจแล้ว

- [ ] **Step 3: รายงานผล**

สรุปผลแต่ละข้อของ checklist (ผ่าน / ไม่ผ่าน / ยังไม่ได้ตรวจ) ตามจริง
