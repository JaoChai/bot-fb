# Connections Page Mobile/Desktop UX Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 4 confirmed High-severity and 1 Medium-severity UX/UI bugs found in a live audit of the "การเชื่อมต่อ" (Connections) page and its sub-pages (BotsPage, BotSettingsPage, AddConnectionPage) on botjao.com, verified on both a 1440px desktop viewport and a 390px mobile viewport.

**Architecture:** Three independent, non-overlapping file changes — no shared interfaces, no new dependencies, no backend changes. Each task is a self-contained Tailwind/JSX fix to one existing React page component, verified with a new Vitest + Testing Library test colocated next to the page, following the existing pattern in `frontend/src/pages/ChatPage.test.tsx` (mock hooks with `vi.mock`, render inside `MemoryRouter`).

**Tech Stack:** React 19, TypeScript, Tailwind CSS v4, Vitest, @testing-library/react.

## Global Constraints

- No new npm dependencies.
- Do not change any Thai copy/strings except where a task explicitly adds new copy.
- Do not touch files or lines outside what each task's `Files:` section lists.
- `line-clamp-*` and `bg-gradient-to-l` are built into Tailwind v4 — no plugin needed.
- After each task: `cd frontend && npx tsc --noEmit` must pass with zero new errors, and the task's own test file must pass.
- These are visual/layout fixes — GLM/the implementer must NOT judge whether it "looks good"; correctness is defined entirely by the test assertions in each task. A human will do the final visual check on the real page afterward.

---

### Task 1: BotsPage — readable name/timestamp on mobile, accessible action buttons, bot count

**Files:**
- Modify: `frontend/src/pages/BotsPage.tsx:222-280`
- Test: `frontend/src/pages/BotsPage.test.tsx` (create)

**Interfaces:**
- Consumes: existing `useBots()` from `@/hooks/useKnowledgeBase` (already imported in this file) — returns `{ data: { data: Bot[] }, isLoading: boolean, error: Error | null }`. Each `Bot` has at least `{ id: number, name: string, channel_type: string, status: string, updated_at: string }`.
- Produces: nothing consumed by other tasks — fully self-contained.

**Context (why):** On a 390px-wide phone, three things are confirmed broken by live testing on production:
1. `bot.name` is on one `truncate` line, so two bots both named starting with "Line" become indistinguishable ("Line Support ..." / "Line - Adsva...").
2. The platform label and "อัพเดต X ชั่วโมงที่แล้ว" timestamp are one `truncate`-d string, so the timestamp is silently cut off entirely on narrow screens.
3. The "Flow" and "ตั้งค่า" icon buttons have no `aria-label`. Their text label (`<span className="hidden sm:inline">`) uses `hidden`, which also removes the text from the accessibility tree below the `sm` breakpoint, so screen reader users get two unlabeled icon buttons. The "เมนูเพิ่มเติม" button right next to them already does this correctly (`aria-label="เมนูเพิ่มเติม"`) — follow that existing pattern.
4. (Medium, M1) The list gives no indication of how many bots exist — add a one-line count above the list, useful once an account has more than a couple of bots.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/pages/BotsPage.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { BotsPage } from './BotsPage';

const BOTS = [
  {
    id: 28,
    name: 'Line Support Adsvance',
    channel_type: 'line',
    status: 'active',
    updated_at: new Date(Date.now() - 6 * 3600 * 1000).toISOString(),
  },
  {
    id: 29,
    name: 'Line - Adsvance',
    channel_type: 'line',
    status: 'active',
    updated_at: new Date(Date.now() - 9 * 3600 * 1000).toISOString(),
  },
];

vi.mock('@/hooks/useKnowledgeBase', () => ({
  useBots: () => ({ data: { data: BOTS }, isLoading: false, error: null }),
}));

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/bots']}>
      <BotsPage />
    </MemoryRouter>
  );
}

describe('BotsPage', () => {
  it('does not truncate the bot name to a single ellipsized line', () => {
    renderPage();
    const name = screen.getByText('Line Support Adsvance');
    expect(name).not.toHaveClass('truncate');
    expect(name).toHaveClass('line-clamp-2');
  });

  it('keeps the updated-time text in its own element, not swallowed by a shared truncated string', () => {
    renderPage();
    // The platform label and the "อัพเดต ..." time must be independently findable text nodes.
    expect(screen.getAllByText('LINE Official Account').length).toBeGreaterThan(0);
    expect(screen.getAllByText(/^อัพเดต /).length).toBe(BOTS.length);
  });

  it('gives the Flow and settings icon buttons an accessible name per bot', () => {
    renderPage();
    expect(screen.getByRole('button', { name: 'เปิด Flow ของ Line Support Adsvance' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ตั้งค่า Line Support Adsvance' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'เปิด Flow ของ Line - Adsvance' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ตั้งค่า Line - Adsvance' })).toBeInTheDocument();
  });

  it('shows a total bot count above the list', () => {
    renderPage();
    expect(screen.getByText('ทั้งหมด 2 บอท')).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/BotsPage.test.tsx`
Expected: FAIL — `line-clamp-2` class not present, aria-labels not found, count text not found.

- [ ] **Step 3: Fix BotsPage.tsx**

In `frontend/src/pages/BotsPage.tsx`, replace lines 222-238 (the "Main: name + meta" block) with:

```tsx
                    {/* Main: name + meta */}
                    <button
                      type="button"
                      onClick={() => navigate(`/bots/${bot.id}/settings`)}
                      className="flex-1 min-w-0 text-left focus:outline-none"
                    >
                      <div className="flex items-start gap-2">
                        <h3 className="font-medium line-clamp-2 break-words">{bot.name}</h3>
                        <StatusDot
                          status={isActive ? 'active' : 'inactive'}
                          pulse={isActive}
                          className="mt-1 shrink-0"
                        />
                      </div>
                      <p className="text-xs text-muted-foreground truncate">
                        {PLATFORM_LABEL[platform]}
                      </p>
                      <p className="text-xs text-muted-foreground truncate tabular-nums">
                        อัพเดต {formatRelativeTime(bot.updated_at)}
                      </p>
                    </button>
```

Then update the two action buttons currently at lines 242-249 and 250-257 (inside the "Trailing: quick actions" block) to add `aria-label`:

```tsx
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`เปิด Flow ของ ${bot.name}`}
                        onClick={() => navigate(`/flows/editor?botId=${bot.id}`)}
                      >
                        <Workflow className="size-4 mr-1" strokeWidth={1.5} />
                        <span className="hidden sm:inline">Flow</span>
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        aria-label={`ตั้งค่า ${bot.name}`}
                        onClick={() => navigate(`/bots/${bot.id}/settings`)}
                      >
                        <Settings className="size-4 mr-1" strokeWidth={1.5} />
                        <span className="hidden sm:inline">ตั้งค่า</span>
                      </Button>
```

Finally, add the bot count line. Immediately after the closing `/>` of the `<Toolbar ... />` component (right before the `{/* Filtered-to-zero message */}` comment), insert:

```tsx
          <p className="text-sm text-muted-foreground">ทั้งหมด {filtered.length} บอท</p>

```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/BotsPage.test.tsx`
Expected: PASS, all 4 tests green.

- [ ] **Step 5: Type-check**

Run: `cd frontend && npx tsc --noEmit`
Expected: no new errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/BotsPage.tsx frontend/src/pages/BotsPage.test.tsx
git commit -m "fix(bots): แก้ชื่อบอท/เวลาอัพเดตหายบนมือถือ + ใส่ aria-label ปุ่ม Flow/ตั้งค่า + จำนวนบอท"
```

---

### Task 2: BotSettingsPage — fix the mobile page-shift bug and add a scroll hint on the tab bar

**Files:**
- Modify: `frontend/src/pages/BotSettingsPage.tsx:340-365`
- Test: `frontend/src/pages/BotSettingsPage.test.tsx` (create)

**Interfaces:**
- Consumes: existing `useBotSettings(botId)` and `useUpdateBotSettings(botId)` from `@/hooks/useBotSettings` (already imported) — `useBotSettings` returns `{ data: BotSettings | undefined, isLoading: boolean }`; `useUpdateBotSettings` returns `{ mutateAsync: (payload) => Promise<...>, isPending: boolean }`. Also consumes `useParams<{ botId: string }>()` from `react-router`.
- Produces: nothing consumed by other tasks — fully self-contained.

**Context (why):** Confirmed by live DOM measurement on production at 390px width: the `<aside>` wrapping the tab nav (line 341) is a CSS Grid item with no `min-w-0`. CSS Grid items default to `min-width: auto`, so the grid track (and everything up the tree to the app shell's `<main class="overflow-auto">`) is forced to grow to fit the tab row's full intrinsic width (measured: `main.scrollWidth` became 449px on a 390px viewport). Because of this, clicking ANY tab makes the browser's built-in "scroll element into view" behavior drag the *entire page* — including the header and breadcrumb above the tab bar — sideways, clipping the left edge. This was reproduced on every one of the 5 tabs.

Live-testing the fix (`aside.style.minWidth = '0px'` via devtools) confirmed a one-line fix resolves it completely: `main.scrollWidth` dropped to exactly 390px (matching the viewport, zero overflow), and it also happened to fix a second symptom — a slider max-value label ("1,000") that was rendering 14px past the right edge of the screen inside the "ข้อจำกัด" tab — without touching that tab's own file at all. **Do not modify `RateLimitTab.tsx` for this — it is not the cause.**

Separately (smaller, cosmetic): even after the fix above, the tab row still needs its own internal horizontal scroll on very narrow phones to reach all 5 tabs, and currently gives no visual hint that more tabs exist off-screen to the right. Add a thin fade-out gradient on the right edge, visible only below the `md` breakpoint.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/pages/BotSettingsPage.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { BotSettingsPage } from './BotSettingsPage';

vi.mock('react-router', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router')>();
  return { ...actual, useParams: () => ({ botId: '28' }) };
});

vi.mock('@/hooks/useBotSettings', () => ({
  useBotSettings: () => ({
    data: {
      daily_message_limit: 500,
      per_user_limit: 30,
      response_hours_enabled: false,
      response_hours: null,
    },
    isLoading: false,
  }),
  useUpdateBotSettings: () => ({ mutateAsync: vi.fn(), isPending: false }),
}));

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/bots/28/settings']}>
      <BotSettingsPage />
    </MemoryRouter>
  );
}

describe('BotSettingsPage', () => {
  it('gives the tab-nav aside min-w-0 so it cannot force the page wider than the viewport', () => {
    const { container } = renderPage();
    const aside = container.querySelector('aside');
    expect(aside).not.toBeNull();
    expect(aside).toHaveClass('min-w-0');
  });

  it('shows a right-edge scroll-hint fade next to the tab row for narrow screens', () => {
    renderPage();
    const fade = screen.getByTestId('tab-scroll-fade');
    expect(fade).toHaveClass('md:hidden');
    expect(fade).toHaveAttribute('aria-hidden', 'true');
  });

  it('still renders all 5 tab buttons', () => {
    renderPage();
    for (const label of ['ข้อจำกัด', 'เวลาตอบกลับ', 'พฤติกรรม', 'สติกเกอร์', 'ตรวจสลิป']) {
      expect(screen.getByRole('button', { name: label })).toBeInTheDocument();
    }
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/BotSettingsPage.test.tsx`
Expected: FAIL — `aside` has no `min-w-0` class, `tab-scroll-fade` testid does not exist.

- [ ] **Step 3: Fix BotSettingsPage.tsx**

Replace lines 340-365 of `frontend/src/pages/BotSettingsPage.tsx`:

```tsx
      <div className="grid gap-6 md:grid-cols-[220px_1fr] md:gap-8">
        <aside className="md:border-r md:pr-6">
          <nav className="flex md:flex-col gap-1 overflow-x-auto md:overflow-visible -mx-1 px-1">
            {TABS.map((t) => {
              const Icon = t.icon;
              const isActive = tab === t.value;
              return (
                <button
                  key={t.value}
                  type="button"
                  onClick={() => setTab(t.value)}
                  className={cn(
                    'relative flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors text-left shrink-0',
                    'before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-4 before:w-0.5 before:rounded-full before:bg-primary before:transition-opacity',
                    isActive
                      ? 'bg-accent text-foreground before:opacity-100'
                      : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground before:opacity-0',
                  )}
                >
                  <Icon className="size-4 shrink-0" strokeWidth={1.5} />
                  <span>{t.label}</span>
                </button>
              );
            })}
          </nav>
        </aside>
```

with:

```tsx
      <div className="grid gap-6 md:grid-cols-[220px_1fr] md:gap-8">
        <aside className="min-w-0 md:border-r md:pr-6">
          <div className="relative">
            <nav className="flex md:flex-col gap-1 overflow-x-auto md:overflow-visible -mx-1 px-1">
              {TABS.map((t) => {
                const Icon = t.icon;
                const isActive = tab === t.value;
                return (
                  <button
                    key={t.value}
                    type="button"
                    onClick={() => setTab(t.value)}
                    className={cn(
                      'relative flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors text-left shrink-0',
                      'before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-4 before:w-0.5 before:rounded-full before:bg-primary before:transition-opacity',
                      isActive
                        ? 'bg-accent text-foreground before:opacity-100'
                        : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground before:opacity-0',
                    )}
                  >
                    <Icon className="size-4 shrink-0" strokeWidth={1.5} />
                    <span>{t.label}</span>
                  </button>
                );
              })}
            </nav>
            <div
              data-testid="tab-scroll-fade"
              aria-hidden="true"
              className="pointer-events-none absolute inset-y-0 right-0 w-8 bg-gradient-to-l from-background to-transparent md:hidden"
            />
          </div>
        </aside>
```

(Only the `<aside>` open tag gains `min-w-0`, the `<nav>` gets wrapped in a new `<div className="relative">`, and one new `<div data-testid="tab-scroll-fade">` is added right after `</nav>` — everything else in this block, including every prop on the tab `<button>`, is unchanged.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/BotSettingsPage.test.tsx`
Expected: PASS, all 3 tests green.

- [ ] **Step 5: Type-check**

Run: `cd frontend && npx tsc --noEmit`
Expected: no new errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/BotSettingsPage.tsx frontend/src/pages/BotSettingsPage.test.tsx
git commit -m "fix(bot-settings): กัน grid ดันหน้ากว้างเกินจอมือถือตอนกดแท็บ (min-w-0) + ใส่ fade hint ว่าเลื่อนแท็บได้อีก"
```

---

### Task 3: AddConnectionPage — fix the cramped step-2 indicator on mobile

**Files:**
- Modify: `frontend/src/pages/AddConnectionPage.tsx:122-135`
- Test: `frontend/src/pages/AddConnectionPage.test.tsx` (create)

**Interfaces:**
- Consumes: nothing external beyond `useNavigate` from `react-router` (already imported) and local component state — fully self-contained.
- Produces: nothing consumed by other tasks — fully self-contained.

**Context (why):** Confirmed on production at 390px width: after picking a platform with a long name ("LINE Official Account"), the step-2 header (`✓ <platform name> ── ② ตั้งค่าการเชื่อมต่อ` plus a "เปลี่ยนแพลตฟอร์ม" button, all in one `flex items-center justify-between` row with no wrapping allowed) has no room, so the platform-name text wraps mid-row and visually collides with the step-2 label and the button next to it. Step 1's platform-picker grid (lines 94-118) is untouched by this task and already reads fine on mobile — do not modify it.

- [ ] **Step 1: Write the failing test**

Create `frontend/src/pages/AddConnectionPage.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import userEvent from '@testing-library/user-event';
import { AddConnectionPage } from './AddConnectionPage';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/connections/add']}>
      <AddConnectionPage />
    </MemoryRouter>
  );
}

describe('AddConnectionPage', () => {
  it('stacks the step-2 header vertically on narrow screens and truncates a long platform name instead of wrapping it', async () => {
    const user = userEvent.setup();
    renderPage();

    await user.click(screen.getByRole('button', { name: /LINE Official Account/ }));

    const changeButton = screen.getByRole('button', { name: 'เปลี่ยนแพลตฟอร์ม' });
    // The step row + the change-platform button share a flex-col-on-mobile parent.
    const stepRow = changeButton.parentElement;
    expect(stepRow).toHaveClass('flex-col');
    expect(stepRow).toHaveClass('sm:flex-row');

    const nameSpan = screen.getByText('LINE Official Account');
    expect(nameSpan).toHaveClass('truncate');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/AddConnectionPage.test.tsx`
Expected: FAIL — parent has no `flex-col`/`sm:flex-row`, name span has no `truncate`.

- [ ] **Step 3: Fix AddConnectionPage.tsx**

Replace lines 122-135 of `frontend/src/pages/AddConnectionPage.tsx`:

```tsx
          {/* Step 2 indicator + change platform */}
          <div className="flex items-center justify-between gap-4">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <span className="inline-flex size-6 items-center justify-center rounded-full border text-xs font-semibold text-muted-foreground">✓</span>
              <span>{selectedPlatformData?.name}</span>
              <div className="h-px flex-1 bg-border max-w-[80px]" />
              <span className="inline-flex size-6 items-center justify-center rounded-full bg-primary text-primary-foreground text-xs font-semibold">2</span>
              <span className="font-medium text-foreground">ตั้งค่าการเชื่อมต่อ</span>
            </div>
            <Button variant="ghost" size="sm" onClick={() => setSelectedPlatform(null)}>
              <ArrowLeft className="size-4 mr-1" strokeWidth={1.5} />
              เปลี่ยนแพลตฟอร์ม
            </Button>
          </div>
```

with:

```tsx
          {/* Step 2 indicator + change platform */}
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <div className="flex min-w-0 items-center gap-2 text-sm text-muted-foreground">
              <span className="inline-flex size-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold text-muted-foreground">✓</span>
              <span className="truncate">{selectedPlatformData?.name}</span>
              <div className="h-px flex-1 max-w-[40px] shrink-0 bg-border sm:max-w-[80px]" />
              <span className="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground text-xs font-semibold">2</span>
              <span className="shrink-0 font-medium text-foreground">ตั้งค่าการเชื่อมต่อ</span>
            </div>
            <Button
              variant="ghost"
              size="sm"
              className="shrink-0 self-start sm:self-auto"
              onClick={() => setSelectedPlatform(null)}
            >
              <ArrowLeft className="size-4 mr-1" strokeWidth={1.5} />
              เปลี่ยนแพลตฟอร์ม
            </Button>
          </div>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/AddConnectionPage.test.tsx`
Expected: PASS.

- [ ] **Step 5: Type-check**

Run: `cd frontend && npx tsc --noEmit`
Expected: no new errors.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/AddConnectionPage.tsx frontend/src/pages/AddConnectionPage.test.tsx
git commit -m "fix(add-connection): กันแถบขั้นตอนที่ 2 ตัวหนังสือชนกันบนมือถือ (stack + truncate ชื่อแพลตฟอร์ม)"
```

---

## Not covered by this plan (by design)

- **RateLimitTab.tsx** — the slider max-value overflow originally suspected here is fixed entirely by Task 2; do not touch this file.
- **ResponseHoursTab / BehaviorTab / StickerReplyTab / SlipVerificationTab** — live-tested on mobile, no additional layout bugs found; not in scope.
- **WeekSchedule.tsx** (shown only when "เปิดใช้งานเวลาทำการ" is turned on) — not verified live because that toggle changes real behavior on a production bot currently in use. Out of scope for this plan; flag to the account owner to check separately, ideally on a test bot.
