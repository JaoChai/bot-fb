# bot-fb — Full System Redesign (Linear × Vercel × Attio)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign every surface of bot-fb — layout shell, navigation, all 14 pages, shared primitives, motion/typography/color tokens — to a cohesive **Linear-Minimal SaaS** design language that is measurably **more beautiful and easier to use** than the current mix.

**Architecture:** Foundation-first. We (1) refine design tokens, (2) rebuild layout shell (Sidebar/Header/MobileNav/AuthLayout), (3) lock down primitives in `@/components/connections/*` (the de-facto shared lib — 18 importers), (4) redesign every page top-to-bottom using those primitives. Work ships as one feature branch, split into logical commits so review is manageable.

**Tech Stack:** React 19 + TypeScript + Tailwind v4 (oklch tokens) + shadcn/ui + Radix primitives + lucide-react + Inter + Noto Sans Thai + JetBrains Mono. No new dependencies.

---

## 1. Design Language — Rationale

### What we're replacing
- Sidebar active state = solid `bg-foreground text-background` (high-contrast block — looks heavy, clashes with minimalism).
- Dashboard uses 4 gradient stat cards (blue/emerald/violet/amber) — violates one-accent rule.
- Pages mix `Card + CardHeader + colored-icon-circles` with newer `SettingSection` → inconsistent hierarchy.
- KB/Settings/Team use `bg-primary/10 p-2` icon containers → noisy.
- VIP numbers use `text-amber-700` / `text-purple-700` → color-as-decoration (not semantic).
- AuthLayout testimonial is placeholder Lorem (fake CEO "สมชาย ใจดี").
- Radius = 10px (too soft for the SaaS-density we want).
- No unified skeleton/loading/empty/error language.
- No breadcrumbs on deep pages (KB detail, bot settings → users lose orientation).

### What we're building — "Linear-Minimal SaaS"

| Dimension | Decision | Rationale |
|-----------|----------|-----------|
| **Accent strategy** | 90 % neutral, 10 % single blue accent | Linear/Vercel pattern — color where it matters (primary CTA, active nav, charts), neutral elsewhere |
| **Hue** | Blue h=262° but chroma reduced from 0.245 → 0.18 | Current blue reads "startup-y"; reduce saturation for "professional-tool" vibe |
| **Radius** | 8 px default (was 10 px), 6 px for buttons/inputs, 10 px for cards/overlays | Tighter corners = more precise, technical feel (matches Linear, Attio) |
| **Elevation** | Borders do the lifting. Shadows only on floating surfaces (popover, dialog, toast) | Removes "web 2.0 card lift" aesthetic |
| **Density** | 14 px body (not 16), 12 px labels, 8–12 px row padding | SaaS density — 16 px feels consumer-y on dashboards |
| **Typography** | Inter Variable (latin) + Noto Sans Thai (Thai) + JetBrains Mono (code/IDs). Negative tracking on headings. Tabular figures for numbers. | Already installed Inter + Noto Thai — just need scale + feature-settings discipline |
| **Motion** | 150 ms (micro), 200 ms (medium), 250 ms (route). `cubic-bezier(0.4,0,0.2,1)` ease-out. Interruptible. prefers-reduced-motion respected. | Matches iOS/MD standards, makes UI feel *fast* |
| **Active-state indicator** | 2 px left bar (ring color) + `bg-muted/60` instead of solid fill | Much lighter visual, wayfinding still clear |
| **Hover** | `bg-muted/40` or 2 % opacity shift. Never scale. Always `transition-colors duration-150` | Matches Linear's "calm" surface interactions |
| **Focus** | 2 px ring with 2 px offset (already via `--ring`) | Accessibility mandatory |
| **Icons** | Lucide. 16 px (inline with text), 20 px (buttons), 32 px (empty states). Stroke 1.5. No filled mixed with outlined. | Consistent voice |
| **Numbers** | Always `tabular-nums font-variant-numeric: tabular-nums` | Prevent shift in tables/live counters |
| **Dark mode** | First-class. Every surface designed twice. Dark bg = near-black oklch(0.135 0.014 250) (deepened from current 0.145). | Chatbot work often done at night — must not be afterthought |
| **Status color** | Green (success) / Amber (warning) / Red (destructive) / Blue (info). Used on *indicators only* — never decorative text color. | Color = semantic, not decoration |
| **Empty / Loading / Error** | Three named primitives. Every list/panel uses one. | Removes 20+ ad-hoc versions scattered across pages |
| **Command density** | Breadcrumbs on 2+ level pages, page header with actions on right, sticky action bar at page bottom for forms | Improves wayfinding + action predictability |

### Color token updates (before → after, light mode)

```
--primary:         0.546 0.245 262   →  0.520 0.190 262    (less saturated)
--ring:            0.546 0.245 262   →  0.520 0.190 262
--sidebar-primary: 0.546 0.245 262   →  0.520 0.190 262
--background:      0.985 0.002 250   →  0.99  0.002 250    (slightly brighter)
--border:          0.915 0.006 250   →  0.92  0.004 250    (slightly lighter, less blue)
--muted:           0.955 0.006 250   →  0.965 0.004 250
--radius:          0.625rem (10 px)  →  0.5rem (8 px)
```

Dark mode:
```
--background:      0.145 0.014 250   →  0.135 0.010 250    (deeper, less blue-shift)
--card:            0.195 0.014 250   →  0.180 0.010 250
--border:          0.28  0.012 250   →  0.235 0.010 250
--primary:         0.62  0.22  262   →  0.62  0.18  262    (less saturated)
```

### Typography scale (locked)

```
display     Inter 600  32/36  tracking -0.025em
h1          Inter 600  24/30  tracking -0.02em
h2          Inter 600  18/26  tracking -0.015em
h3/section  Inter 500  14/20  tracking -0.005em
body        Inter 400  14/22  tracking 0
body-sm     Inter 400  13/20
label       Inter 500  12/16  uppercase tracking 0.05em (section labels)
mono        JetBrains Mono 400  13/20  (IDs, API keys, code)
caption     Inter 400  12/16
```

Thai copy automatically falls back to Noto Sans Thai (already configured in `@layer base`).

---

## 2. File Structure

**New files (8):**
- `frontend/src/components/common/Section.tsx` — alias/refinement of SettingSection with `icon`, `actions`, `tone` props
- `frontend/src/components/common/Row.tsx` — alias/refinement of SettingRow
- `frontend/src/components/common/Metric.tsx` — flat stat card
- `frontend/src/components/common/EmptyState.tsx`
- `frontend/src/components/common/ErrorState.tsx`
- `frontend/src/components/common/BotPicker.tsx`
- `frontend/src/components/common/Toolbar.tsx` — search + filters + actions row
- `frontend/src/components/common/Breadcrumb.tsx`
- `frontend/src/components/common/index.ts` — barrel, re-exports PageHeader/Section/Row/StickyActionBar from connections for convenience

**Modified tokens / globals (1):**
- `frontend/src/index.css` — update oklch values + radius + add typography utilities (font-feature-settings)

**Modified layout shell (5):**
- `frontend/src/components/layout/Sidebar.tsx`
- `frontend/src/components/layout/Header.tsx`
- `frontend/src/components/layout/MobileNav.tsx`
- `frontend/src/components/layout/AuthLayout.tsx`
- `frontend/src/components/layout/RootLayout.tsx` (add max-width container)

**Modified pages (14):**
- `frontend/src/pages/DashboardPage.tsx`
- `frontend/src/pages/BotsPage.tsx` (refined from PR #130)
- `frontend/src/pages/AddConnectionPage.tsx` (refined)
- `frontend/src/pages/EditConnectionPage.tsx` (refined)
- `frontend/src/pages/BotSettingsPage.tsx` (refined)
- `frontend/src/pages/FlowEditorPage.tsx` (refined from PR #131)
- `frontend/src/pages/ChatPage.tsx`
- `frontend/src/pages/KnowledgeBasePage.tsx`
- `frontend/src/pages/OrdersPage.tsx`
- `frontend/src/pages/SettingsPage.tsx`
- `frontend/src/pages/TeamPage.tsx`
- `frontend/src/pages/VipManagementPage.tsx`
- `frontend/src/pages/settings/QuickRepliesPage.tsx`
- `frontend/src/pages/auth/LoginPage.tsx`
- `frontend/src/pages/auth/RegisterPage.tsx`

**Modified components (~6):**
- `frontend/src/components/connections/PageHeader.tsx` — extend API (breadcrumb, meta)
- `frontend/src/components/connections/SettingSection.tsx` — add icon/actions/tone props
- `frontend/src/components/dashboard/DashboardStatCard.tsx` — delegate to new Metric
- `frontend/src/components/dashboard/BusinessHealthBar.tsx` — neutralize colors, use Row style
- `frontend/src/components/dashboard/CompactCostBreakdown.tsx` — flat surface
- `frontend/src/components/dashboard/CompactStockToggle.tsx` — use Row

**Verification pipeline** (run after each task):
```bash
cd /home/jaochai/code/bot-fb/frontend
npx tsc --noEmit && npm run lint
```

At phase boundaries (Task 5, Task 12, Task 20):
```bash
npm run build && npx knip --reporter compact
```

Manual browser QA at the end. Branch: `feature/full-system-redesign`.

---

## 3. Task Sequence

Tasks are numbered so order matters. Each ships a commit. Phases = logical review checkpoints.

### Phase A — Foundation (Tasks 1–4)

#### Task 1: Branch + token refresh

**Files:**
- Modify: `frontend/src/index.css`

- [ ] **Step 1: Create branch**

```bash
cd /home/jaochai/code/bot-fb
git checkout -b feature/full-system-redesign
```

- [ ] **Step 2: Update `:root` tokens**

Replace the `:root` block in `frontend/src/index.css` (lines ~57–130) — keep structure, update values:

```css
:root {
  --radius: 0.5rem; /* was 0.625rem */

  /* Linear-Minimal SaaS — Light */

  --background: oklch(0.99 0.002 250);
  --foreground: oklch(0.145 0.01 250);

  --card: oklch(1 0 0);
  --card-foreground: oklch(0.145 0.01 250);

  --popover: oklch(1 0 0);
  --popover-foreground: oklch(0.145 0.01 250);

  --primary: oklch(0.520 0.190 262);
  --primary-foreground: oklch(0.99 0 0);

  --secondary: oklch(0.965 0.004 250);
  --secondary-foreground: oklch(0.25 0.01 250);

  --muted: oklch(0.965 0.004 250);
  --muted-foreground: oklch(0.48 0.01 250);

  --accent: oklch(0.955 0.008 250);
  --accent-foreground: oklch(0.25 0.01 250);

  --destructive: oklch(0.577 0.245 27.325);

  --border: oklch(0.92 0.004 250);
  --input: oklch(0.92 0.004 250);
  --ring: oklch(0.520 0.190 262);

  --chart-1: oklch(0.520 0.190 262);
  --chart-2: oklch(0.627 0.194 149);
  --chart-3: oklch(0.705 0.213 47);
  --chart-4: oklch(0.585 0.233 277);
  --chart-5: oklch(0.645 0.246 16);

  --sidebar: oklch(0.985 0.002 250);
  --sidebar-foreground: oklch(0.145 0.01 250);
  --sidebar-primary: oklch(0.520 0.190 262);
  --sidebar-primary-foreground: oklch(0.99 0 0);
  --sidebar-accent: oklch(0.955 0.008 250);
  --sidebar-accent-foreground: oklch(0.25 0.01 250);
  --sidebar-border: oklch(0.92 0.004 250);
  --sidebar-ring: oklch(0.520 0.190 262);

  --success: oklch(0.627 0.194 149);
  --success-foreground: oklch(0.985 0 0);
  --warning: oklch(0.795 0.184 86);
  --warning-foreground: oklch(0.15 0 0);
  --info: oklch(0.520 0.190 262);
  --info-foreground: oklch(0.985 0 0);
  --inactive: oklch(0.92 0.004 250);
  --inactive-foreground: oklch(0.48 0.01 250);

  --platform-line: oklch(0.723 0.219 145);
  --platform-facebook: oklch(0.511 0.262 263);
}
```

- [ ] **Step 3: Update `.dark` block** (lines ~132–203)

```css
.dark {
  --background: oklch(0.135 0.010 250);
  --foreground: oklch(0.985 0.002 250);

  --card: oklch(0.180 0.010 250);
  --card-foreground: oklch(0.985 0.002 250);

  --popover: oklch(0.180 0.010 250);
  --popover-foreground: oklch(0.985 0.002 250);

  --primary: oklch(0.62 0.180 262);
  --primary-foreground: oklch(0.985 0 0);

  --secondary: oklch(0.235 0.008 250);
  --secondary-foreground: oklch(0.985 0.002 250);

  --muted: oklch(0.235 0.008 250);
  --muted-foreground: oklch(0.66 0.008 250);

  --accent: oklch(0.235 0.010 250);
  --accent-foreground: oklch(0.985 0.002 250);

  --destructive: oklch(0.704 0.191 22.216);

  --border: oklch(0.235 0.010 250);
  --input: oklch(0.235 0.010 250);
  --ring: oklch(0.62 0.180 262);

  --chart-1: oklch(0.65 0.18 262);
  --chart-2: oklch(0.70 0.19 149);
  --chart-3: oklch(0.75 0.18 47);
  --chart-4: oklch(0.65 0.20 277);
  --chart-5: oklch(0.70 0.22 16);

  --sidebar: oklch(0.125 0.010 250);
  --sidebar-foreground: oklch(0.985 0.002 250);
  --sidebar-primary: oklch(0.62 0.180 262);
  --sidebar-primary-foreground: oklch(0.985 0 0);
  --sidebar-accent: oklch(0.210 0.012 250);
  --sidebar-accent-foreground: oklch(0.985 0.002 250);
  --sidebar-border: oklch(0.235 0.010 250);
  --sidebar-ring: oklch(0.62 0.180 262);

  --success: oklch(0.70 0.19 149);
  --success-foreground: oklch(0.985 0 0);
  --warning: oklch(0.795 0.184 86);
  --warning-foreground: oklch(0.15 0 0);
  --info: oklch(0.62 0.180 262);
  --info-foreground: oklch(0.985 0 0);
  --inactive: oklch(0.235 0.010 250);
  --inactive-foreground: oklch(0.66 0.008 250);

  --platform-line: oklch(0.723 0.219 145);
  --platform-facebook: oklch(0.65 0.22 262);
}
```

- [ ] **Step 4: Add typography utilities to `@layer base`** (replace existing `body` rule)

```css
@layer base {
  * {
    @apply border-border outline-ring/50;
  }
  html {
    font-feature-settings: 'cv11', 'ss01', 'ss03'; /* Inter refined letterforms */
  }
  body {
    @apply bg-background text-foreground antialiased;
    font-family: 'Inter', 'Noto Sans Thai', system-ui, -apple-system, sans-serif;
    font-feature-settings: 'cv11', 'ss01', 'ss03';
  }
  .tabular-nums, table, .font-mono {
    font-variant-numeric: tabular-nums;
  }

  /* Safe area utilities */
  .pb-safe { padding-bottom: env(safe-area-inset-bottom, 0px); }
  .pt-safe { padding-top: env(safe-area-inset-top, 0px); }
  .pl-safe { padding-left: env(safe-area-inset-left, 0px); }
  .pr-safe { padding-right: env(safe-area-inset-right, 0px); }
}
```

- [ ] **Step 5: Verify**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 6: Commit**

```bash
git add frontend/src/index.css
git commit -m "refactor(theme): refine oklch tokens for Linear-Minimal aesthetic"
```

---

#### Task 2: New primitives — part 1 (`EmptyState`, `ErrorState`, `Metric`)

**Files:**
- Create: `frontend/src/components/common/EmptyState.tsx`
- Create: `frontend/src/components/common/ErrorState.tsx`
- Create: `frontend/src/components/common/Metric.tsx`
- Create: `frontend/src/components/common/index.ts`

- [ ] **Step 1: `EmptyState.tsx`**

```tsx
import type { ReactNode, ElementType } from 'react';
import { cn } from '@/lib/utils';

interface EmptyStateProps {
  icon?: ElementType;
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
  size?: 'sm' | 'md' | 'lg';
}

export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
  size = 'md',
}: EmptyStateProps) {
  const pad = size === 'sm' ? 'py-8 px-4' : size === 'lg' ? 'py-16 px-6' : 'py-12 px-6';
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center rounded-lg border border-dashed bg-muted/20 text-center',
        pad,
        className,
      )}
    >
      {Icon && (
        <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-md border bg-background">
          <Icon className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
        </div>
      )}
      <h3 className="text-sm font-medium">{title}</h3>
      {description && (
        <p className="mt-1 max-w-sm text-sm text-muted-foreground">{description}</p>
      )}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}
```

- [ ] **Step 2: `ErrorState.tsx`**

```tsx
import type { ReactNode } from 'react';
import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

interface ErrorStateProps {
  title?: string;
  description?: string;
  action?: ReactNode;
  className?: string;
}

export function ErrorState({
  title = 'เกิดข้อผิดพลาด',
  description,
  action,
  className,
}: ErrorStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center rounded-lg border border-destructive/30 bg-destructive/5 px-6 py-10 text-center',
        className,
      )}
      role="alert"
    >
      <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-md border border-destructive/30 bg-background">
        <AlertCircle className="h-4 w-4 text-destructive" strokeWidth={1.5} />
      </div>
      <h3 className="text-sm font-medium text-destructive">{title}</h3>
      {description && <p className="mt-1 max-w-sm text-sm text-muted-foreground">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}
```

- [ ] **Step 3: `Metric.tsx`**

```tsx
import type { ElementType, ReactNode } from 'react';
import { ArrowDown, ArrowUp, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';

type Trend = { value: number; direction: 'up' | 'down' | 'stable' };

interface MetricProps {
  label: string;
  value: ReactNode;
  hint?: ReactNode;
  icon?: ElementType;
  trend?: Trend;
  className?: string;
}

export function Metric({ label, value, hint, icon: Icon, trend, className }: MetricProps) {
  const TrendIcon =
    trend?.direction === 'up' ? ArrowUp : trend?.direction === 'down' ? ArrowDown : Minus;
  const trendTone =
    trend?.direction === 'up'
      ? 'text-emerald-600 dark:text-emerald-400'
      : trend?.direction === 'down'
      ? 'text-destructive'
      : 'text-muted-foreground';

  return (
    <div className={cn('rounded-lg border bg-card p-4', className)}>
      <div className="flex items-center justify-between">
        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
        {Icon && <Icon className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />}
      </div>
      <p className="mt-2 text-2xl font-semibold tabular-nums leading-none">{value}</p>
      {(trend || hint) && (
        <div className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
          {trend && (
            <span className={cn('inline-flex items-center gap-0.5 tabular-nums', trendTone)}>
              <TrendIcon className="h-3 w-3" strokeWidth={2} />
              {trend.value}%
            </span>
          )}
          {hint && <span className="truncate">{hint}</span>}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 4: `index.ts` barrel**

```ts
export { EmptyState } from './EmptyState';
export { ErrorState } from './ErrorState';
export { Metric } from './Metric';
// Re-export existing primitives for one-stop import:
export { PageHeader, SettingSection, SettingRow, StickyActionBar } from '@/components/connections';
```

- [ ] **Step 5: Verify**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/common
git commit -m "feat(ui): add EmptyState, ErrorState, Metric primitives"
```

---

#### Task 3: New primitives — part 2 (`BotPicker`, `Toolbar`, `Breadcrumb`)

**Files:**
- Create: `frontend/src/components/common/BotPicker.tsx`
- Create: `frontend/src/components/common/Toolbar.tsx`
- Create: `frontend/src/components/common/Breadcrumb.tsx`
- Modify: `frontend/src/components/common/index.ts`

- [ ] **Step 1: `BotPicker.tsx`**

```tsx
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Bot } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface BotOption { id: number | string; name: string }

interface BotPickerProps {
  bots: BotOption[];
  value?: string | number;
  onChange: (id: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  showIcon?: boolean;
}

export function BotPicker({
  bots, value, onChange, placeholder = 'เลือกบอท', disabled, className, showIcon = true,
}: BotPickerProps) {
  return (
    <Select
      value={value !== undefined ? String(value) : undefined}
      onValueChange={onChange}
      disabled={disabled}
    >
      <SelectTrigger className={cn('w-full', className)}>
        {showIcon && <Bot className="h-4 w-4 text-muted-foreground mr-2" strokeWidth={1.5} />}
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        {bots.map((bot) => (
          <SelectItem key={bot.id} value={String(bot.id)}>{bot.name}</SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
```

- [ ] **Step 2: `Toolbar.tsx`**

```tsx
import type { ReactNode } from 'react';
import { Search } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

interface ToolbarProps {
  search?: string;
  onSearchChange?: (v: string) => void;
  searchPlaceholder?: string;
  filters?: ReactNode;
  actions?: ReactNode;
  className?: string;
}

export function Toolbar({
  search, onSearchChange, searchPlaceholder = 'ค้นหา...', filters, actions, className,
}: ToolbarProps) {
  return (
    <div className={cn('flex flex-wrap items-center gap-2', className)}>
      {onSearchChange && (
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
          <Input
            value={search ?? ''}
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder={searchPlaceholder}
            className="pl-9"
          />
        </div>
      )}
      {filters && <div className="flex items-center gap-2">{filters}</div>}
      {actions && <div className="ml-auto flex items-center gap-2">{actions}</div>}
    </div>
  );
}
```

- [ ] **Step 3: `Breadcrumb.tsx`**

```tsx
import { Fragment } from 'react';
import { Link } from 'react-router';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface BreadcrumbItem {
  label: string;
  to?: string;
}

interface BreadcrumbProps {
  items: BreadcrumbItem[];
  className?: string;
}

export function Breadcrumb({ items, className }: BreadcrumbProps) {
  return (
    <nav aria-label="breadcrumb" className={cn('flex items-center gap-1 text-sm', className)}>
      {items.map((item, i) => {
        const isLast = i === items.length - 1;
        return (
          <Fragment key={i}>
            {item.to && !isLast ? (
              <Link
                to={item.to}
                className="text-muted-foreground transition-colors hover:text-foreground"
              >
                {item.label}
              </Link>
            ) : (
              <span className={cn(isLast ? 'text-foreground font-medium' : 'text-muted-foreground')}>
                {item.label}
              </span>
            )}
            {!isLast && <ChevronRight className="h-3.5 w-3.5 text-muted-foreground/60" strokeWidth={1.5} />}
          </Fragment>
        );
      })}
    </nav>
  );
}
```

- [ ] **Step 4: Update barrel**

Append to `frontend/src/components/common/index.ts`:
```ts
export { BotPicker } from './BotPicker';
export type { BotOption } from './BotPicker';
export { Toolbar } from './Toolbar';
export { Breadcrumb } from './Breadcrumb';
export type { BreadcrumbItem } from './Breadcrumb';
```

- [ ] **Step 5: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/components/common
git commit -m "feat(ui): add BotPicker, Toolbar, Breadcrumb primitives"
```

---

#### Task 4: Extend existing primitives (PageHeader, SettingSection)

**Files:**
- Modify: `frontend/src/components/connections/PageHeader.tsx`
- Modify: `frontend/src/components/connections/SettingSection.tsx`

First read both files to see actual current signatures:

```bash
cat frontend/src/components/connections/PageHeader.tsx
cat frontend/src/components/connections/SettingSection.tsx
```

- [ ] **Step 1: `PageHeader` API — add `breadcrumb` and `meta` props**

Target signature:
```tsx
interface PageHeaderProps {
  title: string;
  description?: string;
  actions?: ReactNode;
  breadcrumb?: BreadcrumbItem[];  // NEW
  meta?: ReactNode;                // NEW — right-aligned meta under actions (e.g. date, status)
  onBack?: () => void;
  backTo?: string | null;
  backLabel?: string;
}
```

Target markup shape:
```tsx
<header className="flex flex-col gap-2">
  {breadcrumb && <Breadcrumb items={breadcrumb} />}
  <div className="flex items-start justify-between gap-4">
    <div className="min-w-0">
      {(onBack || backTo) && (
        <Button variant="ghost" size="sm" onClick={onBack} className="mb-2 -ml-2">
          <ChevronLeft className="h-4 w-4 mr-1" /> {backLabel ?? 'กลับ'}
        </Button>
      )}
      <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
      {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
    </div>
    {(actions || meta) && (
      <div className="flex flex-col items-end gap-1 shrink-0">
        {actions && <div className="flex items-center gap-2">{actions}</div>}
        {meta && <div className="text-sm text-muted-foreground">{meta}</div>}
      </div>
    )}
  </div>
</header>
```

Inspect the current PageHeader to preserve any call-site compat. If call sites use positional children or different prop names, add the new props **non-breakingly** (with defaults). Do **not** remove existing props.

- [ ] **Step 2: `SettingSection` API — add `icon`, `actions`, `tone` props**

Target signature:
```tsx
interface SettingSectionProps {
  title: string;
  description?: string;
  children: ReactNode;
  icon?: ElementType;       // NEW
  actions?: ReactNode;      // NEW — right-aligned in header
  tone?: 'default' | 'destructive';  // NEW — border color variant
  className?: string;
}
```

Target markup:
```tsx
<section className={cn(
  'rounded-lg border bg-card',
  tone === 'destructive' && 'border-destructive/40',
  className,
)}>
  <div className="flex items-start justify-between gap-4 border-b px-5 py-4">
    <div className="flex items-center gap-3 min-w-0">
      {Icon && <Icon className="h-4 w-4 text-muted-foreground shrink-0" strokeWidth={1.5} />}
      <div className="min-w-0">
        <h2 className="text-sm font-medium">{title}</h2>
        {description && <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>}
      </div>
    </div>
    {actions && <div className="flex items-center gap-2 shrink-0">{actions}</div>}
  </div>
  <div className="px-5 py-4">{children}</div>
</section>
```

Ensure existing call sites (18 importers) still render correctly — only add new optional props.

- [ ] **Step 3: Verify compatibility**

```bash
cd frontend && npx tsc --noEmit && npm run lint && npm run build
```

Expected: 0 errors across all 18 existing importers.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/connections/PageHeader.tsx frontend/src/components/connections/SettingSection.tsx
git commit -m "feat(ui): extend PageHeader+SettingSection API non-breakingly"
```

---

### Phase B — Layout Shell (Tasks 5–8)

#### Task 5: Sidebar redesign

**Files:**
- Modify: `frontend/src/components/layout/Sidebar.tsx`

Current issues:
- Active state `bg-foreground text-background` = heavy black/white block — too loud.
- No section grouping (everything is a flat list).
- No keyboard shortcut hint.
- Bot icon used for "การเชื่อมต่อ" but "/bots" route name is stale — keep as-is, just visual refresh.

- [ ] **Step 1: Replace active-state + add section labels**

Active state becomes: `bg-accent text-foreground` + 2 px left bar `before:` pseudo-element.

```tsx
// Replace the NavLink className function:
className={({ isActive }) =>
  cn(
    'relative flex items-center gap-3 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
    'before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-4 before:w-0.5 before:rounded-full before:bg-primary before:opacity-0 before:transition-opacity',
    isActive
      ? 'bg-accent text-foreground before:opacity-100'
      : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
    sidebarCollapsed && 'justify-center px-2 before:hidden',
  )
}
```

- [ ] **Step 2: Group navigation into "WORKSPACE" and "MANAGEMENT"**

Add `<div className="px-3 pt-4 pb-1 text-xs font-medium uppercase tracking-wider text-muted-foreground">` section labels above each group:
- **Workspace:** Dashboard, Orders, VIP, Chat
- **Management:** Connections (Bots), Knowledge Base, Team (owner), Quick Replies (owner)

(Skip labels when collapsed.)

- [ ] **Step 3: Logo polish**

Replace `bg-foreground text-background` logo tile with `bg-primary/10 text-primary border border-primary/20` for softer brand presence:
```tsx
<div className="flex h-7 w-7 items-center justify-center rounded-md bg-primary/10 text-primary border border-primary/20">
  <Sparkles className="h-3.5 w-3.5" strokeWidth={2} />
</div>
```

- [ ] **Step 4: Shrink nav item padding**

`py-2` → `py-1.5`, icon size stays `h-4 w-4`, overall sidebar feels tighter.

- [ ] **Step 5: User dropdown polish**

In the user dropdown menu trigger, replace hard-fill hover with `hover:bg-accent/60`. Keep everything else.

- [ ] **Step 6: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/components/layout/Sidebar.tsx
git commit -m "refactor(sidebar): Linear-style active indicator + grouped sections"
```

---

#### Task 6: MobileNav — mirror Sidebar changes

**Files:**
- Modify: `frontend/src/components/layout/MobileNav.tsx`

- [ ] **Step 1: Apply same active-state pattern, logo polish, nav grouping as Task 5** to MobileNav.

- [ ] **Step 2: Add section labels** ("WORKSPACE" / "MANAGEMENT") — always visible since mobile is never collapsed.

- [ ] **Step 3: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/components/layout/MobileNav.tsx
git commit -m "refactor(mobile-nav): mirror sidebar redesign"
```

---

#### Task 7: Header refinement

**Files:**
- Modify: `frontend/src/components/layout/Header.tsx`

Current: mobile-only hamburger + blank space. Adequate, minor polish.

- [ ] **Step 1: Add backdrop-blur for sticky feel**

Replace `bg-background` with `bg-background/80 backdrop-blur-sm supports-[backdrop-filter]:bg-background/60`.

- [ ] **Step 2: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/components/layout/Header.tsx
git commit -m "refactor(header): add translucent backdrop for sticky feel"
```

---

#### Task 8: AuthLayout — replace placeholder testimonial, polish brand side

**Files:**
- Modify: `frontend/src/components/layout/AuthLayout.tsx`

Current left side has fake Lorem testimonial from "คุณสมชาย ใจดี, CEO, Example Company". Replace with a product value-prop grid (no fake social proof).

- [ ] **Step 1: Replace testimonial with product highlights**

```tsx
<div className="hidden lg:flex flex-col justify-between bg-foreground p-10 text-background">
  {/* Logo */}
  <div className="flex items-center gap-3">
    <div className="flex h-9 w-9 items-center justify-center rounded-md bg-background/10 border border-background/20">
      <Sparkles className="h-4 w-4" strokeWidth={2} />
    </div>
    <span className="text-lg font-semibold tracking-tight">BotJao</span>
  </div>

  {/* Value prop */}
  <div className="space-y-8">
    <div className="space-y-3">
      <h2 className="text-3xl font-semibold tracking-tight leading-tight">
        AI Chatbot <br />สำหรับธุรกิจไทย
      </h2>
      <p className="text-base text-background/70 leading-relaxed max-w-md">
        จัดการแชท LINE และ Telegram ด้วย AI ที่เรียนรู้จากฐานความรู้ของคุณ ตอบลูกค้าได้ 24 ชั่วโมง
      </p>
    </div>

    <ul className="space-y-3 text-sm text-background/80">
      {[
        'เชื่อมต่อ LINE Official & Telegram ใน 5 นาที',
        'ฐานความรู้ RAG ที่ AI ใช้ตอบลูกค้าอย่างแม่นยำ',
        'แดชบอร์ดยอดขาย + ต้นทุน API แบบเรียลไทม์',
        'จัดการทีม Admin + VIP อัตโนมัติ',
      ].map((f) => (
        <li key={f} className="flex items-start gap-2">
          <CheckCircle2 className="h-4 w-4 text-background/60 mt-0.5 shrink-0" strokeWidth={1.5} />
          <span>{f}</span>
        </li>
      ))}
    </ul>
  </div>

  {/* Footer */}
  <p className="text-xs text-background/50">
    © {new Date().getFullYear()} BotJao · AI Chatbot Platform
  </p>
</div>
```

Add import: `import { Sparkles, CheckCircle2 } from 'lucide-react';`

- [ ] **Step 2: Mobile logo polish**

Change mobile logo block (inside form side) to match new logo tile style + subtitle:
```tsx
<div className="mb-8 text-center lg:hidden">
  <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary border border-primary/20">
    <Sparkles className="h-5 w-5" strokeWidth={2} />
  </div>
  <h1 className="text-xl font-semibold tracking-tight">BotJao</h1>
  <p className="text-sm text-muted-foreground">AI Chatbot Platform</p>
</div>
```

- [ ] **Step 3: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/components/layout/AuthLayout.tsx
git commit -m "refactor(auth-layout): replace placeholder testimonial with value props"
```

**Checkpoint:** Phase B complete. Run `npm run build` to confirm shell is green before touching pages.

---

### Phase C — Existing Pages Refinement (Tasks 9–11)

These pages already got a Linear pass in PR #130/#131 — now align them to the *new* token/primitive baseline.

#### Task 9: Refine Bots / AddConnection / EditConnection pages

**Files:**
- Modify: `frontend/src/pages/BotsPage.tsx`
- Modify: `frontend/src/pages/AddConnectionPage.tsx`
- Modify: `frontend/src/pages/EditConnectionPage.tsx`

- [ ] **Step 1: Audit each page for:**
  - [ ] Uses `PageHeader` — if not, add it
  - [ ] No gradient/colored-border cards (`className="border-blue-200/50 bg-gradient-to-br …"`)
  - [ ] No `bg-primary/10 p-2` icon circles
  - [ ] Tabular-nums on any numeric display
  - [ ] Empty states use `EmptyState` from `@/components/common`
  - [ ] Primary CTA is `Button` default variant (blue); destructive uses `variant="destructive"`
  - [ ] Hover on bot rows = `hover:bg-muted/40 transition-colors` (not scale or shadow)
- [ ] **Step 2: Apply fixes** inline. Do not rewrite — surgical edits only.
- [ ] **Step 3: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/BotsPage.tsx frontend/src/pages/AddConnectionPage.tsx frontend/src/pages/EditConnectionPage.tsx
git commit -m "refactor(connections): align with new design tokens and primitives"
```

---

#### Task 10: Refine BotSettings + FlowEditor + Flow sidebar

**Files:**
- Modify: `frontend/src/pages/BotSettingsPage.tsx`
- Modify: `frontend/src/pages/FlowEditorPage.tsx`
- Modify any components in `frontend/src/components/bot-settings/` and `frontend/src/components/flow-editor/` that show colored pills, gradients, or ad-hoc rows

- [ ] **Step 1: Same audit checklist as Task 9** applied to these pages and their tab components.
- [ ] **Step 2: Ensure every tab uses `SettingSection` + `SettingRow`** (should already be the case — verify).
- [ ] **Step 3: `StickyActionBar`** on pages with dirty-state → use neutral tone only; "Unsaved" badge uses `variant="secondary"`.
- [ ] **Step 4: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/BotSettingsPage.tsx frontend/src/pages/FlowEditorPage.tsx frontend/src/components/bot-settings frontend/src/components/flow-editor
git commit -m "refactor(bot-settings,flow-editor): align with new design tokens"
```

---

#### Task 11: Flows sidebar + BehaviorTab polish

**Files:**
- Modify: `frontend/src/components/flows/*`
- Modify: `frontend/src/components/bot-settings/BehaviorTab.tsx`

- [ ] **Step 1: Same checklist.** Commit together with Task 10 if fully verified — else ship separately.

---

### Phase D — Page Redesigns (Tasks 12–20)

#### Task 12: DashboardPage (full redesign)

**Files:**
- Modify: `frontend/src/pages/DashboardPage.tsx`
- Modify: `frontend/src/components/dashboard/DashboardStatCard.tsx`
- Modify: `frontend/src/components/dashboard/BusinessHealthBar.tsx`
- Modify: `frontend/src/components/dashboard/CompactCostBreakdown.tsx`
- Modify: `frontend/src/components/dashboard/CompactStockToggle.tsx`

- [ ] **Step 1: `DashboardStatCard.tsx`** — delegate to `Metric` from common:

```tsx
import type { ElementType, ReactNode } from 'react';
import { Metric } from '@/components/common';

interface Props {
  title: string;
  value: ReactNode;
  description?: ReactNode;
  icon?: ElementType;
  trend?: { value: number; direction: 'up' | 'down' | 'stable' };
}

export function DashboardStatCard({ title, value, description, icon, trend }: Props) {
  return <Metric label={title} value={value} hint={description} icon={icon} trend={trend} />;
}
```

(Drop the `className` gradient-border prop entirely — callers no longer pass it.)

- [ ] **Step 2: Rewrite `DashboardPage.tsx`** — the version from `2026-04-18-remaining-pages-linear-redesign.md` Task 4 (PageHeader + neutral stat grid + remove gradient classes). Copy verbatim.

- [ ] **Step 3: `BusinessHealthBar.tsx` audit** — open the file and ensure:
  - No `bg-gradient-to-*`, no `border-*-200/50`
  - Bot status uses `SettingRow`-like layout (name + handle count on left, status indicator on right)
  - Alerts use semantic tone (destructive, warning) only on inline icon + badge — not whole-row coloring

- [ ] **Step 4: `CompactCostBreakdown.tsx` + `CompactStockToggle.tsx`** — wrap content in `SettingSection` if they're currently using `Card`.

- [ ] **Step 5: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/DashboardPage.tsx frontend/src/components/dashboard
git commit -m "refactor(dashboard): Linear-Minimal redesign with flat Metric + neutral surfaces"
```

---

#### Task 13: KnowledgeBasePage (list + detail)

**Files:**
- Modify: `frontend/src/pages/KnowledgeBasePage.tsx`

- [ ] **Step 1: Rewrite** using the version from `2026-04-18-remaining-pages-linear-redesign.md` Task 5, but with these adjustments for the new baseline:
  - Replace `StatCard` from `@/components/connections` with `Metric` from `@/components/common`.
  - Replace `EmptyState` import path: `@/components/common` (not `@/components/connections`).
  - Detail view breadcrumb: `[{ label: 'ฐานความรู้', to: '/knowledge-base' }, { label: knowledgeBase.name }]` via `<PageHeader breadcrumb={...} />`
  - KB grid cards: keep `<button>` semantic + `hover:bg-muted/40 transition-colors` (no shadow).

- [ ] **Step 2: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/KnowledgeBasePage.tsx
git commit -m "refactor(kb): Linear-Minimal redesign for list + detail views"
```

---

#### Task 14: SettingsPage

**Files:**
- Modify: `frontend/src/pages/SettingsPage.tsx`

- [ ] **Step 1: Rewrite** — use Task 6 skeleton from `2026-04-18-remaining-pages-linear-redesign.md` with adjustments:
  - Use `SettingSection` with its new `icon` + `actions` + `tone` props (from Task 4).
  - Quick Replies link card: use `Row` pattern via the existing `SettingRow` with chevron on right OR a bordered flat link row as shown in the linked doc — pick whichever reads cleaner.
  - Danger Zone: `<SettingSection tone="destructive" title="Danger Zone" description="…">…</SettingSection>`
  - Remove `<Separator />` dividers between sections (the section borders already separate them).

- [ ] **Step 2: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/SettingsPage.tsx
git commit -m "refactor(settings): use extended SettingSection + drop colored icon circles"
```

---

#### Task 15: TeamPage

**Files:**
- Modify: `frontend/src/pages/TeamPage.tsx`

- [ ] **Step 1: Apply transformations** (from earlier plan Task 7):
  - Drop `container max-w-4xl py-6` wrapper.
  - Add `<PageHeader title="จัดการทีม" description="…" actions={<BotPicker …/>} />` — BotPicker is in the header actions (not a separate Card).
  - "Add Admin" becomes `<SettingSection title="เพิ่ม Admin" …>` with search input + results list inside.
  - Admin list rows: each row `<div className="flex items-center justify-between rounded-md border px-3 py-2 hover:bg-muted/30">` with avatar, name/email, conversation count badge, remove button.
  - "Auto-Assignment" section uses `SettingRow` for the enable toggle and mode select.
  - Empty state uses `EmptyState icon={Users} title="ยังไม่มี Admin สำหรับ Bot นี้" />`.

- [ ] **Step 2: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/TeamPage.tsx
git commit -m "refactor(team): Linear-Minimal redesign with BotPicker + SettingSection"
```

---

#### Task 16: VipManagementPage

**Files:**
- Modify: `frontend/src/pages/VipManagementPage.tsx`

- [ ] **Step 1: Apply transformations:**
  - `<PageHeader title="ลูกค้า VIP" description="…" actions={<><BotPicker /><Button>เพิ่ม VIP</Button></>} />`
  - 3 summary cards → 3 `<Metric>` — **remove `text-amber-700` / `text-purple-700`** decorative colors; values stay neutral `text-foreground`. Trend/tone only if semantic.
  - Table keeps `Card` wrapper (table needs the rounded frame for overflow-x).
  - Table toolbar (search + count) → use `Toolbar` primitive above the table (move out of CardHeader).
  - Empty state "เลือกบอทเพื่อดูรายการ VIP" → `<EmptyState>`.
  - Error state → `<ErrorState>` with retry.

- [ ] **Step 2: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/VipManagementPage.tsx
git commit -m "refactor(vip): Linear-Minimal redesign with Metric + neutral tone"
```

---

#### Task 17: ChatPage

**Files:**
- Modify: `frontend/src/pages/ChatPage.tsx`

The 3-column structure is right. Just update chrome.

- [ ] **Step 1: Replace** the `Select` at top of left panel with `<BotPicker>`.
- [ ] **Step 2: Replace** the "Select a Bot" full-page empty state (lines ~178–206) with `<EmptyState icon={MessageSquare} title="เลือกบอท" description="เลือกบอทเพื่อดูการสนทนา" action={<BotPicker bots={bots} onChange={handleBotSelect} />} />`.
- [ ] **Step 3: Replace** the "Select a conversation" center-panel empty state (lines ~292–301) with `<EmptyState icon={MessageSquare} title="เลือกการสนทนา" description="เลือกการสนทนาจากรายการเพื่อเริ่มต้น" size="lg" />`.
- [ ] **Step 4: "Reset All Contexts"** button: keep but change from full-width below the picker to a ghost icon button in a new small toolbar row with a tooltip. (Reduces visual weight.)
- [ ] **Step 5: Conversation list** items: audit that `selected` state uses `bg-accent` + `text-foreground` consistently (not solid primary).
- [ ] **Step 6: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/ChatPage.tsx
git commit -m "refactor(chat): adopt BotPicker + EmptyState, lighten reset-all button"
```

---

#### Task 18: OrdersPage (thin wrapper)

**Files:**
- Modify: `frontend/src/pages/OrdersPage.tsx`

- [ ] **Step 1: Rewrite** with `PageHeader` (from `2026-04-18-remaining-pages-linear-redesign.md` Task 10).
- [ ] **Step 2:** Audit `OrdersAnalytics` component (if it exists and has gradient chrome, note for follow-up — do not redesign the analytics component in this task).
- [ ] **Step 3: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/OrdersPage.tsx
git commit -m "refactor(orders): adopt PageHeader"
```

---

#### Task 19: Auth pages (Login + Register)

**Files:**
- Modify: `frontend/src/pages/auth/LoginPage.tsx`
- Modify: `frontend/src/pages/auth/RegisterPage.tsx`

- [ ] **Step 1: Login polish:**
  - Title: `text-2xl font-semibold tracking-tight` (was `font-semibold` — good already).
  - Error banner: `rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm` with `AlertCircle` icon 4×4, `strokeWidth={1.5}`.
  - Input min-height 40 px (check `<Input>` default — should be fine).
  - Primary submit: `Button type="submit" className="w-full" size="default"`.
  - Add "ลืมรหัสผ่าน?" link under the password field — `to="/forgot-password"` (route doesn't exist yet → OK, link target is stub).

- [ ] **Step 2: Register polish:** mirror Login structure + same chrome.

- [ ] **Step 3: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/auth
git commit -m "refactor(auth): polish error state + form spacing + add forgot link"
```

---

#### Task 20: QuickRepliesPage

**Files:**
- Modify: `frontend/src/pages/settings/QuickRepliesPage.tsx`

The biggest remaining file (553 lines). Do a focused pass, not a rewrite:

- [ ] **Step 1: Audit** the current page — read it fully first. Identify these patterns to fix:
  - Ad-hoc `<div>` page header → replace with `<PageHeader>`.
  - `Card + CardHeader` wrappers → `SettingSection`.
  - Colored icon circles → flat.
  - Empty states → `EmptyState`.
  - Search/filter bar → `Toolbar`.
- [ ] **Step 2: Apply targeted edits** (no structural rewrite unless a section is clearly broken).
- [ ] **Step 3: Verify + commit**

```bash
cd frontend && npx tsc --noEmit && npm run lint
cd .. && git add frontend/src/pages/settings/QuickRepliesPage.tsx
git commit -m "refactor(quick-replies): Linear-Minimal alignment"
```

---

### Phase E — Verification & Release (Tasks 21–22)

#### Task 21: Full verification pass

- [ ] **Step 1: Production build**

```bash
cd frontend && npm run build
```
Expected: 0 TS errors. Fix any `verbatimModuleSyntax` issues with `import type`.

- [ ] **Step 2: Dead code scan**

```bash
npx knip --reporter compact
```
Expected: ≤ pre-existing count. Remove any unused props/imports introduced by this refactor.

- [ ] **Step 3: Lint**

```bash
npm run lint
```

- [ ] **Step 4: Manual click-through in browser**

Start `npm run dev`. Walk every route in **both light and dark mode**:

| Route | Must verify |
|-------|-------------|
| `/login`, `/register` | AuthLayout brand side + form |
| `/dashboard` | Header, 4 stat tiles, charts, bot list, cost breakdown |
| `/orders` | Header, analytics |
| `/vip-customers` | Header, BotPicker, 3 metrics, table, empty bot state |
| `/bots` | Page, bot cards |
| `/bots/{id}/settings` | Tabs, sticky save bar |
| `/bots/new` | Form + sticky actions |
| `/knowledge-base` | List grid, empty state, dialog |
| `/knowledge-base` detail view | Breadcrumb, 4 metrics, upload, list, search |
| `/chat` | 3-column layout, BotPicker, empty states |
| `/flows/{id}` | Full flow editor with tabs |
| `/team` | Owner-only, admin CRUD |
| `/settings` | API key section, profile, danger zone |
| `/settings/quick-replies` | List + create |

Checklist per page:
- [ ] No layout shift on load
- [ ] Empty states render when data empty
- [ ] Loading states render (use React Query DevTools to simulate)
- [ ] Error states render (disconnect network)
- [ ] Keyboard nav: Tab through all interactive elements, visible focus rings
- [ ] Dark mode: every surface readable (4.5:1 contrast)
- [ ] Mobile (375 px): nav sheet opens, no horizontal scroll, touch targets ≥ 44 px

- [ ] **Step 5: Fix any regressions.** Commit fixes separately.

---

#### Task 22: PR

- [ ] **Step 1: Push + PR**

```bash
git push -u origin feature/full-system-redesign
gh pr create --title "feat(ui): full-system Linear-Minimal redesign" --body "$(cat <<'EOF'
## Summary
Complete design overhaul: refined oklch tokens (less saturated blue, tighter 8 px radius, deeper dark mode), rebuilt layout shell (sidebar 2-px accent bar, grouped sections, translucent header, real auth brand side), new shared primitives (EmptyState, ErrorState, Metric, BotPicker, Toolbar, Breadcrumb), extended PageHeader + SettingSection APIs, then redesigned every page (Dashboard, KB, Settings, Team, VIP, Chat, Orders, Auth, QuickReplies) and re-aligned already-shipped pages (Bots, Connections, BotSettings, FlowEditor) to the same baseline.

### Before / After — key deltas
- Sidebar active: solid black/white → 2 px accent bar + subtle bg
- Dashboard: 4 gradient stat cards → neutral flat metrics
- Decorative colors on VIP/KB numbers removed — color is now semantic only
- `Card+CardHeader+icon-circle` → `SettingSection` everywhere
- Testimonial on auth → real value-prop list
- Every page now starts with `PageHeader`; breadcrumbs on deep views
- Unified empty / error / loading language

## Test plan
- [ ] `npm run build` passes
- [ ] `npx knip --reporter compact` ≤ baseline
- [ ] `npm run lint` clean
- [ ] Click-through every route in light + dark on desktop (≥ 1440 px)
- [ ] Click-through every route on mobile (375 px)
- [ ] Keyboard nav sweep (Tab + Enter + Escape)
- [ ] No behavioral regressions (all forms submit, all mutations succeed)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 2: Wait for CI.** Fix any failures. Do not merge until:
  - Frontend Checks: green
  - Backend Tests: green (should be unaffected — no backend changes)
  - No reviewer blockers

---

## 4. Explicitly Out of Scope (for follow-up PRs)

- Visual regression test suite (Playwright + screenshot diff)
- Command palette (Cmd+K) — big feature, deserves own design round
- i18n audit (Thai ↔ English consistency)
- Chart library swap or analytics component (`OrdersAnalytics`, `DualAxisChart`) redesign — may need its own plan
- Settings page sub-routes (`/settings/notifications`, etc.) — don't exist yet
- Mobile bottom-nav pattern swap (sheet → tabs) — requires UX validation

---

## 5. Self-Review Checklist

- [x] Every task lists exact files
- [x] Design language rules declared once at top, referenced throughout
- [x] Token changes include before → after values (not just "update colors")
- [x] Primitives created before consumers use them (Phase A before D)
- [x] Layout shell built before page redesigns (Phase B before C/D)
- [x] Existing pages get a refinement pass (Phase C), not ignored
- [x] Verification pipeline defined per-task + phase-boundaries + final
- [x] Manual QA matrix covers every route
- [x] Out-of-scope items called out explicitly
- [x] Branch strategy: single feature branch, 22 commits, one PR
- [x] No placeholders, no "TODO", every step has concrete content
