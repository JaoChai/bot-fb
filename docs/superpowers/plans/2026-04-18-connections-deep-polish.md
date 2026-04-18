# bot-fb Connections Flow — Deep Polish Plan

> **For agentic workers:** Use superpowers:subagent-driven-development or executing-plans.

**Goal:** Make the Connections menu (BotsPage, AddConnection, EditConnection, BotSettings + all its tabs + all credential/section components) feel as polished as Stripe/Linear/Vercel admin panels — and **consistent** with the rest of the app that was redesigned in PR #132.

**Diagnosis of current state (post PR #130 + #132):**

1. **Consistency break:** The connections forms still use `SettingSection` (flat, no border) — while every other page post-#132 uses `Panel` (bordered card) from `@/components/common`. This makes connection pages look visually *older* than the rest of the app.
2. **BotsPage cards too thin:** only name + platform badge; no status dot, no last-message timestamp, no message count, no inline actions. Users can't scan the list.
3. **BotSettingsPage wasted space:** `max-w-4xl` narrow + horizontal tabs + no breadcrumb + no dirty-state warning + no tab-level badges. On desktop this looks like a mobile form.
4. **EditConnectionPage danger placement:** delete button inline in header actions; should be a Danger Zone at page bottom (pattern from SettingsPage).
5. **Credential inputs don't feel safe/premium:** just password `Input`s — no lock icon, no monospace finesse, no "secure" visual cue.
6. **ResponseHoursTab** renders 7 days × N time slots as stacked lists — no visual schedule grid.
7. **Help text inconsistency:** Telegram credentials use `bg-[#0088CC]/5` brand tint; everything else uses neutral. Should be neutral throughout.
8. **AddConnectionPage platform cards** are uniform gray — no brand accent for LINE (green) / Facebook (blue) / Telegram (cyan) that would make them scannable.
9. **Tabs** use shadcn default `TabsList` — which is pill-style. Stripe/Linear use underline tabs or vertical tabs for settings.

**Architecture:** Foundation (new primitives) → layout shell (BotSettings vertical tabs + dirty state) → form sections (convert SettingSection→Panel) → page chrome (richer BotsPage list + danger zone). One feature branch, one PR.

**Tech Stack:** React 19 + TS + Tailwind v4 + shadcn/ui + lucide-react.

---

## File Structure

**New files (3):**
- `frontend/src/components/common/PlatformBadge.tsx` — LINE/Facebook/Telegram/testing badge with brand accent
- `frontend/src/components/common/StatusDot.tsx` — active/inactive/error/warning dot
- `frontend/src/components/bot-settings/WeekSchedule.tsx` — visual 7-day schedule grid editor

**Modified primitives (2):**
- `frontend/src/components/common/Panel.tsx` — add `secure` variant (lock icon slot + subtle gradient) and refine header density
- `frontend/src/components/connections/index.ts` — nothing changes but keep for consistency

**Modified pages (4):**
- `frontend/src/pages/BotsPage.tsx` — richer list rows (status dot + platform + metrics + inline actions + Toolbar)
- `frontend/src/pages/AddConnectionPage.tsx` — platform cards with brand accent + progress indicator
- `frontend/src/pages/EditConnectionPage.tsx` — convert sections to Panel + move delete to Danger Zone
- `frontend/src/pages/BotSettingsPage.tsx` — wider layout + vertical-tabs OR polished underline tabs + dirty-state badge + breadcrumb

**Modified sections (5 → all convert to Panel):**
- `frontend/src/components/connections/sections/BasicInfoSection.tsx`
- `frontend/src/components/connections/sections/AIModelsSection.tsx`
- `frontend/src/components/connections/sections/LineCredentialsSection.tsx`
- `frontend/src/components/connections/sections/TelegramCredentialsSection.tsx`
- `frontend/src/components/connections/sections/AdvancedOptionsSection.tsx`

**Modified bot-settings tabs (4 → wrap in Panel where appropriate):**
- `frontend/src/components/bot-settings/BehaviorTab.tsx`
- `frontend/src/components/bot-settings/RateLimitTab.tsx`
- `frontend/src/components/bot-settings/ResponseHoursTab.tsx` — plus integrate WeekSchedule
- `frontend/src/components/bot-settings/StickerReplyTab.tsx`

**Verification:** `npx tsc --noEmit && npm run lint` per task, `npm run build` at phase boundaries.

---

## Design Language Deltas (vs PR #132 baseline)

- **Panel** remains the canonical bordered section — NOW extended with `secure` variant for credential sections (left accent bar in blue, lock icon, slightly different bg tint via `bg-card`).
- **Brand accents** used **only** on Platform badges and AddConnection platform picker cards — never on section headers or content surfaces.
- **Status dots** are small solid circles (h-2 w-2) with semantic colors: emerald-500 (active), amber-500 (warning), destructive (error), muted-foreground (inactive).
- **Vertical tabs on desktop** for BotSettings (md+) → `grid-cols-[200px_1fr] gap-8` pattern. Horizontal underline tabs fallback on mobile.
- **Dirty-state badge** = `<Badge variant="outline" className="text-xs">● มีการเปลี่ยนแปลง</Badge>` in the header, combined with the sticky action bar.
- **Danger Zone** = `<Panel tone="destructive" title="Danger Zone" description="...">` at the bottom of Edit page.

---

## Task Sequence

### Phase A — Foundation (Tasks 1–2, parallel)

#### Task 1: `PlatformBadge` + `StatusDot` primitives

**Files:**
- Create: `frontend/src/components/common/PlatformBadge.tsx`
- Create: `frontend/src/components/common/StatusDot.tsx`
- Modify: `frontend/src/components/common/index.ts`

Full code:

```tsx
// PlatformBadge.tsx
import type { ElementType } from 'react';
import { MessageCircle, Send, Facebook, TestTube } from 'lucide-react';
import { cn } from '@/lib/utils';

type Platform = 'line' | 'facebook' | 'telegram' | 'testing';

const CONFIG: Record<Platform, { label: string; icon: ElementType; tone: string; accent: string }> = {
  line: {
    label: 'LINE',
    icon: MessageCircle,
    tone: 'text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 dark:border-emerald-900',
    accent: 'bg-emerald-500',
  },
  facebook: {
    label: 'Facebook',
    icon: Facebook,
    tone: 'text-blue-700 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/40 border-blue-200 dark:border-blue-900',
    accent: 'bg-blue-500',
  },
  telegram: {
    label: 'Telegram',
    icon: Send,
    tone: 'text-sky-700 dark:text-sky-400 bg-sky-50 dark:bg-sky-950/40 border-sky-200 dark:border-sky-900',
    accent: 'bg-sky-500',
  },
  testing: {
    label: 'Testing',
    icon: TestTube,
    tone: 'text-muted-foreground bg-muted border-border',
    accent: 'bg-muted-foreground',
  },
};

interface PlatformBadgeProps {
  platform: Platform;
  size?: 'sm' | 'md';
  showLabel?: boolean;
  className?: string;
}

export function PlatformBadge({ platform, size = 'sm', showLabel = true, className }: PlatformBadgeProps) {
  const c = CONFIG[platform];
  const Icon = c.icon;
  const sizeClass = size === 'sm' ? 'h-5 text-xs px-1.5' : 'h-6 text-sm px-2';
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-md border font-medium',
        sizeClass,
        c.tone,
        className,
      )}
    >
      <Icon className={size === 'sm' ? 'h-3 w-3' : 'h-3.5 w-3.5'} strokeWidth={2} />
      {showLabel && <span>{c.label}</span>}
    </span>
  );
}

export type { Platform };
```

```tsx
// StatusDot.tsx
import { cn } from '@/lib/utils';

type Status = 'active' | 'warning' | 'error' | 'inactive';

const COLORS: Record<Status, string> = {
  active: 'bg-emerald-500',
  warning: 'bg-amber-500',
  error: 'bg-destructive',
  inactive: 'bg-muted-foreground/40',
};

interface StatusDotProps {
  status: Status;
  label?: string;
  pulse?: boolean;
  className?: string;
}

export function StatusDot({ status, label, pulse = false, className }: StatusDotProps) {
  return (
    <span className={cn('inline-flex items-center gap-1.5', className)}>
      <span className="relative inline-flex">
        {pulse && status === 'active' && (
          <span className={cn('absolute inline-flex h-2 w-2 rounded-full opacity-60 animate-ping', COLORS[status])} />
        )}
        <span className={cn('inline-flex h-2 w-2 rounded-full', COLORS[status])} />
      </span>
      {label && <span className="text-xs text-muted-foreground">{label}</span>}
    </span>
  );
}

export type { Status as StatusType };
```

Append to `frontend/src/components/common/index.ts`:
```ts
export { PlatformBadge } from './PlatformBadge';
export type { Platform } from './PlatformBadge';
export { StatusDot } from './StatusDot';
export type { StatusType } from './StatusDot';
```

Verify + commit:
```bash
cd frontend && npx tsc --noEmit && npm run lint
git add frontend/src/components/common
git commit -m "feat(ui): add PlatformBadge + StatusDot primitives"
```

---

#### Task 2: Extend `Panel` with `secure` variant

**Files:**
- Modify: `frontend/src/components/common/Panel.tsx`

Current `Panel` signature (from PR #132): `title, description, icon, actions, children, tone='default'|'destructive', className, bodyClassName`.

Add `tone='secure'` as a new option:
- Adds a left border accent (2 px) in primary color
- Header icon bg gets `bg-primary/5 text-primary border-primary/20`
- Body keeps neutral `bg-card`

Updated implementation:

```tsx
import type { ReactNode, ElementType } from 'react';
import { cn } from '@/lib/utils';

interface PanelProps {
  title?: string;
  description?: string;
  icon?: ElementType;
  actions?: ReactNode;
  children: ReactNode;
  tone?: 'default' | 'destructive' | 'secure';
  className?: string;
  bodyClassName?: string;
}

export function Panel({
  title,
  description,
  icon: Icon,
  actions,
  children,
  tone = 'default',
  className,
  bodyClassName,
}: PanelProps) {
  return (
    <section
      className={cn(
        'rounded-lg border bg-card overflow-hidden',
        tone === 'destructive' && 'border-destructive/40',
        tone === 'secure' && 'border-l-2 border-l-primary',
        className,
      )}
    >
      {(title || actions) && (
        <header className="flex items-start justify-between gap-4 border-b px-5 py-4">
          <div className="flex items-start gap-3 min-w-0">
            {Icon && (
              <div
                className={cn(
                  'flex h-7 w-7 items-center justify-center rounded-md border shrink-0',
                  tone === 'destructive' && 'border-destructive/30 bg-destructive/5 text-destructive',
                  tone === 'secure' && 'border-primary/20 bg-primary/5 text-primary',
                  tone === 'default' && 'bg-muted/40 text-muted-foreground',
                )}
              >
                <Icon className="h-3.5 w-3.5" strokeWidth={1.75} />
              </div>
            )}
            <div className="min-w-0">
              {title && (
                <h2
                  className={cn(
                    'text-sm font-semibold',
                    tone === 'destructive' && 'text-destructive',
                  )}
                >
                  {title}
                </h2>
              )}
              {description && (
                <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
              )}
            </div>
          </div>
          {actions && <div className="flex items-center gap-2 shrink-0">{actions}</div>}
        </header>
      )}
      <div className={cn('px-5 py-4', bodyClassName)}>{children}</div>
    </section>
  );
}
```

Key refinements vs PR #132 version:
- Icon tile shrunk from `h-9 w-9` → `h-7 w-7` (denser, less visually heavy)
- Icon `h-4 w-4` → `h-3.5 w-3.5`
- Title `text-sm font-medium` → `text-sm font-semibold` (more authoritative)
- Description `text-sm` → `text-xs` (tighter)
- Added `overflow-hidden` for left accent bar rendering

Verify that all existing Panel consumers (post-#132) still look OK after this density tightening. They should — this is a refinement, not a breaking change.

Commit:
```bash
cd frontend && npx tsc --noEmit && npm run lint
git add frontend/src/components/common/Panel.tsx
git commit -m "refactor(ui): refine Panel header density + add secure tone"
```

---

### Phase B — Core Pages (Tasks 3–6, parallel after Phase A)

#### Task 3: BotsPage — richer list with status + toolbar

**Files:**
- Modify: `frontend/src/pages/BotsPage.tsx`

Replace the bot grid with a list (vertical rows) showing: platform badge, bot name, handle/ID, status dot with "Active" label (if we can determine), connected time ago, action buttons (Settings, Flow Editor, More).

Keep all existing hooks + mutations. Add a `Toolbar` at the top with:
- Search input (filters by bot name)
- Filter: platform (All / LINE / Facebook / Telegram / Testing) — as small button group or Select
- Right side: "สร้าง Bot ใหม่" button

Use `PlatformBadge` + `StatusDot`.

Status heuristic: treat `bot.is_active !== false && bot.webhook_url` as active; otherwise inactive.

Target row layout:

```tsx
<div className="rounded-lg border bg-card divide-y">
  {filteredBots.map((bot) => (
    <div key={bot.id} className="flex items-center gap-4 px-4 py-3 hover:bg-muted/40 transition-colors group">
      {/* Leading: platform icon large */}
      <div className="shrink-0">
        <PlatformBadge platform={bot.platform as Platform} size="md" showLabel={false} />
      </div>

      {/* Main: name + handle + status */}
      <button
        onClick={() => navigate(`/bots/${bot.id}/settings`)}
        className="flex-1 min-w-0 text-left"
      >
        <div className="flex items-center gap-2">
          <h3 className="font-medium truncate">{bot.name}</h3>
          <StatusDot status={bot.is_active !== false ? 'active' : 'inactive'} pulse={bot.is_active !== false} />
        </div>
        <p className="text-xs text-muted-foreground truncate">
          {PLATFORM_LABEL[bot.platform]} · อัพเดต {formatRelativeTime(bot.updated_at)}
        </p>
      </button>

      {/* Trailing: quick actions */}
      <div className="flex items-center gap-1 shrink-0 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
        <Button variant="ghost" size="sm" onClick={...}>
          <Workflow className="h-4 w-4 mr-1" strokeWidth={1.5} /> Flow
        </Button>
        <Button variant="ghost" size="sm" onClick={...}>
          <Settings className="h-4 w-4 mr-1" strokeWidth={1.5} /> ตั้งค่า
        </Button>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon">
              <MoreHorizontal className="h-4 w-4" strokeWidth={1.5} />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={...}>แก้ไขการเชื่อมต่อ</DropdownMenuItem>
            <DropdownMenuItem onClick={...}>ดูการสนทนา</DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={...} className="text-destructive">ลบบอท</DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </div>
  ))}
</div>
```

Toolbar code:
```tsx
<Toolbar
  search={search}
  onSearchChange={setSearch}
  searchPlaceholder="ค้นหาชื่อบอท..."
  filters={
    <ToggleGroup ...> {/* or Select with 5 options */}
      <ToggleGroupItem value="all">ทั้งหมด</ToggleGroupItem>
      {/* platforms */}
    </ToggleGroup>
  }
  actions={<Button onClick={() => navigate('/bots/new')}><Plus className="h-4 w-4 mr-2" strokeWidth={2} />สร้าง Bot</Button>}
/>
```

Keep the Empty State but add `PlatformBadge` previews inside as "try connecting" CTAs.

Preserve: AlertDialog for delete, all useBots/useDeleteBot hooks, navigate calls.

Commit:
```bash
git add frontend/src/pages/BotsPage.tsx
git commit -m "refactor(bots): richer list rows with PlatformBadge + StatusDot + Toolbar"
```

---

#### Task 4: AddConnectionPage — platform cards with brand accent + progress

**Files:**
- Modify: `frontend/src/pages/AddConnectionPage.tsx`

Platform selection screen (when `!formData.platform`):

- Add a subtle "Step 1 of 2 · เลือกแพลตฟอร์ม" text above the heading.
- The 4 platform cards (LINE / Facebook / Telegram / Testing) should:
  - Have a platform-brand left accent bar (`border-l-2 border-l-emerald-500` for LINE, etc.)
  - Bigger icon (h-8 w-8) top-left with brand color tint on a subtle background
  - Hover: `hover:border-primary/40 hover:bg-muted/30 transition-colors`
  - Show a `<ArrowRight>` on hover at bottom-right
  - Keep text content unchanged (title, description)

After a platform is selected (form screen), add a breadcrumb-like indicator: "เลือก Platform / {platform name} · Step 2 of 2 — ตั้งค่าการเชื่อมต่อ".

Keep the whole form pipeline (sections) unchanged — Phase C Task 7 will convert sections to Panel.

Commit:
```bash
git add frontend/src/pages/AddConnectionPage.tsx
git commit -m "refactor(add-connection): brand-accent platform cards + step indicator"
```

---

#### Task 5: EditConnectionPage — Panel conversion + Danger Zone

**Files:**
- Modify: `frontend/src/pages/EditConnectionPage.tsx`

Changes:

1. Remove the header-area Delete button (the one that's currently in `actions` slot of PageHeader, OR inline). Keep `PageHeader` with `title`, `description`, `backTo`. Actions slot: only "Copy connection ID" or similar utility action if it exists, otherwise no actions.

2. Move the delete button to a new `Panel tone="destructive"` at the bottom of the page:

```tsx
<Panel
  tone="destructive"
  icon={Trash2}
  title="Danger Zone"
  description="การดำเนินการต่อไปนี้ไม่สามารถย้อนกลับได้"
>
  <div className="flex items-center justify-between gap-4">
    <div>
      <p className="text-sm font-medium">ลบการเชื่อมต่อนี้</p>
      <p className="text-xs text-muted-foreground mt-0.5">
        จะลบบอทและการสนทนาทั้งหมดของบอทนี้ — ไม่สามารถกู้คืนได้
      </p>
    </div>
    <Button
      variant="destructive"
      onClick={() => setDeleteDialogOpen(true)}
      disabled={deleteBotMutation.isPending}
    >
      <Trash2 className="h-4 w-4 mr-2" strokeWidth={1.5} />
      ลบบอท
    </Button>
  </div>
</Panel>
```

3. The sections (BasicInfo, Credentials, AIModels, Advanced) are untouched here — they'll be converted to Panel in Phase C Task 7.

Preserve: all state, hooks, mutations, AlertDialog, sticky action bar.

Commit:
```bash
git add frontend/src/pages/EditConnectionPage.tsx
git commit -m "refactor(edit-connection): move delete to Danger Zone Panel at page bottom"
```

---

#### Task 6: BotSettingsPage — vertical tabs + wider layout + dirty state + breadcrumb

**Files:**
- Modify: `frontend/src/pages/BotSettingsPage.tsx`

Changes:

1. Remove `mx-auto max-w-4xl w-full` — let the page breathe full-width per RootLayout.

2. Add breadcrumb to the PageHeader:
   ```tsx
   breadcrumb={[
     { label: 'การเชื่อมต่อ', to: '/bots' },
     { label: `Bot #${botId}`, to: `/bots/${botId}/edit` }, // or bot.name if we can fetch it
     { label: 'ตั้งค่า' },
   ]}
   ```

3. Track dirty state — compare `formData` vs `serverSettings` → boolean `isDirty`. When dirty, show a badge in PageHeader actions:
   ```tsx
   actions={isDirty && <Badge variant="outline" className="text-xs"><span className="h-1.5 w-1.5 rounded-full bg-amber-500 mr-1.5" />มีการเปลี่ยนแปลง</Badge>}
   ```

4. Tab layout — on md+, use vertical sidebar tabs:
   ```tsx
   <div className="grid md:grid-cols-[220px_1fr] gap-6 md:gap-8">
     <aside className="md:border-r md:pr-6">
       <nav className="flex md:flex-col gap-1 overflow-x-auto md:overflow-visible">
         {TABS.map((t) => (
           <button
             key={t.value}
             onClick={() => setTab(t.value)}
             className={cn(
               'relative flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors text-left shrink-0',
               'before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-4 before:w-0.5 before:rounded-full before:bg-primary before:transition-opacity',
               tab === t.value
                 ? 'bg-accent text-foreground before:opacity-100'
                 : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground before:opacity-0',
             )}
           >
             <t.icon className="h-4 w-4 shrink-0" strokeWidth={1.5} />
             <span>{t.label}</span>
           </button>
         ))}
       </nav>
     </aside>

     <div className="min-w-0 space-y-6">
       {tab === 'rate-limit' && <RateLimitTab ... />}
       {tab === 'response-hours' && <ResponseHoursTab ... />}
       {tab === 'behavior' && <BehaviorTab ... />}
       {tab === 'sticker' && <StickerReplyTab ... />}
     </div>
   </div>
   ```

   Use `useState<'rate-limit' | 'response-hours' | 'behavior' | 'sticker'>('rate-limit')` instead of shadcn Tabs component.

   Tab config:
   ```ts
   const TABS = [
     { value: 'rate-limit', label: 'ข้อจำกัด', icon: Gauge },
     { value: 'response-hours', label: 'เวลาตอบกลับ', icon: Clock },
     { value: 'behavior', label: 'พฤติกรรม', icon: Bot },
     { value: 'sticker', label: 'สติกเกอร์', icon: Smile },
   ];
   ```

5. Remove the `Tabs` + `TabsList` + `TabsTrigger` + `TabsContent` shadcn imports if the replacement above fully substitutes them.

6. Keep `StickyActionBar` + save button logic unchanged.

7. The "การเปลี่ยนแปลงจะมีผลทันทีหลังบันทึก" tagline in the sticky bar — keep but also add the dirty indicator to it when dirty: `{isDirty ? 'มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก' : 'การเปลี่ยนแปลงจะมีผลทันทีหลังบันทึก'}`.

Preserve: all form state, onFieldChange, handleSave, all tab props.

Commit:
```bash
git add frontend/src/pages/BotSettingsPage.tsx
git commit -m "refactor(bot-settings): vertical tabs + full-width + dirty state + breadcrumb"
```

---

### Phase C — Section Conversion (Tasks 7–9, parallel after Phase B)

#### Task 7: Convert 5 connections/sections from SettingSection → Panel

**Files:**
- Modify: `frontend/src/components/connections/sections/BasicInfoSection.tsx`
- Modify: `frontend/src/components/connections/sections/AIModelsSection.tsx`
- Modify: `frontend/src/components/connections/sections/LineCredentialsSection.tsx` (use `tone="secure"`)
- Modify: `frontend/src/components/connections/sections/TelegramCredentialsSection.tsx` (use `tone="secure"`)
- Modify: `frontend/src/components/connections/sections/AdvancedOptionsSection.tsx`

For each section: change `import { SettingSection, SettingRow } from '@/components/connections'` to import `SettingRow` from `@/components/connections` AND `Panel` from `@/components/common`. Wrap content in `<Panel>` instead of `<SettingSection>`. Preserve ALL form props and handlers.

For credential sections (LINE, Telegram), pass `tone="secure"` to `Panel`.

**Special extra fixes in TelegramCredentialsSection:**
- Remove `bg-[#0088CC]/5 border border-[#0088CC]/20` brand-tint help box → replace with neutral `bg-muted/30 border rounded-md p-3` (remove the Telegram brand cue — Panel's secure tone carries the vibe now).
- Remove `text-green-500` on the Check icon → use `text-emerald-600 dark:text-emerald-400`.

**In AIModelsSection:**
- It renders TWO sections (OpenRouter API + AI Models). Keep both as separate `Panel`s.
- The "OpenRouter API Key ตั้งค่าที่หน้า Settings เพียงที่เดียว" box currently has `bg-muted/50 rounded-lg px-4 py-3 max-w-md` — keep it but simplify to `rounded-md border bg-muted/30 p-3 max-w-md`.

Commit:
```bash
git add frontend/src/components/connections/sections
git commit -m "refactor(connections/sections): SettingSection→Panel, secure tone for credentials"
```

---

#### Task 8: Convert bot-settings tabs to Panel (BehaviorTab, RateLimitTab, StickerReplyTab)

**Files:**
- Modify: `frontend/src/components/bot-settings/BehaviorTab.tsx`
- Modify: `frontend/src/components/bot-settings/RateLimitTab.tsx`
- Modify: `frontend/src/components/bot-settings/StickerReplyTab.tsx`

Same treatment as Task 7: wrap each `SettingSection` grouping in `<Panel title="..." description="..." icon={...}>` and keep `SettingRow` for field rows.

Special consideration: these tabs have lots of conditional sub-sections (e.g. BehaviorTab shows smart-aggregation sub-fields when `smart_aggregation_enabled`). Wrap the conditional block in a subtle indented container:
```tsx
{flag && (
  <div className="pl-4 border-l-2 border-muted space-y-4">
    {/* sub-fields */}
  </div>
)}
```

Commit:
```bash
git add frontend/src/components/bot-settings/BehaviorTab.tsx frontend/src/components/bot-settings/RateLimitTab.tsx frontend/src/components/bot-settings/StickerReplyTab.tsx
git commit -m "refactor(bot-settings/tabs): convert 3 tabs to Panel + indented sub-fields"
```

---

#### Task 9: ResponseHoursTab + WeekSchedule primitive

**Files:**
- Create: `frontend/src/components/bot-settings/WeekSchedule.tsx` — visual 7-day schedule grid editor
- Modify: `frontend/src/components/bot-settings/ResponseHoursTab.tsx` — use Panel + WeekSchedule

First READ the full current `ResponseHoursTab.tsx` to understand the API (day toggle, slot change, add slot, remove slot, apply to all) and what data it expects.

Design:

`WeekSchedule.tsx` — a compact 7-row grid where each row is a day:
```
[Switch]  จันทร์     [09:00 – 18:00]  [+ add slot]
[Switch]  อังคาร     [09:00 – 12:00] [13:00 – 18:00]  [+ add slot]
...
```

- Day label column: fixed width `w-16` or similar, text-sm font-medium
- Switch toggles the day's `enabled` state
- Time slots are inline pill-style editors with start/end time inputs and a small × to remove
- "Apply to all days" button sits above/below the grid
- When a day is disabled, its row appears at reduced opacity

Props:
```ts
interface WeekScheduleProps {
  schedule: ResponseHoursConfig;
  onDayToggle: (day: DayKey, enabled: boolean) => void;
  onSlotChange: (day: DayKey, slotIndex: number, field: 'start' | 'end', value: string) => void;
  onAddSlot: (day: DayKey) => void;
  onRemoveSlot: (day: DayKey, slotIndex: number) => void;
  onApplyToAllDays: () => void;
}
```

Then update `ResponseHoursTab.tsx`:
- Wrap the enable toggle + timezone selector in a `Panel` called "เวลาทำการ"
- Wrap `<WeekSchedule>` in a `Panel` called "ตารางเวลา" (only show when enabled)
- Wrap the "offline message" textarea in a `Panel` called "ข้อความนอกเวลาทำการ"
- Keep conditional rendering based on `response_hours_enabled`.

Commit:
```bash
git add frontend/src/components/bot-settings
git commit -m "feat(response-hours): visual WeekSchedule grid + Panel conversion"
```

---

### Phase D — Verify + PR (Tasks 10–11)

#### Task 10: Build + knip + manual QA

```bash
cd /home/jaochai/code/bot-fb/frontend
npm run build
npx knip --reporter compact
npm run lint
```
Expected: 0 errors. Any NEW dead exports from our work should be cleaned.

Manual QA walk:
- `/bots` — verify new list rows, status dots, search, filter, actions
- `/bots/new` — platform cards with brand accent, step indicator
- `/bots/{id}/edit` — sections as Panels, secure tone on credentials, Danger Zone at bottom
- `/bots/{id}/settings` — vertical tabs, dirty badge appears when editing, schedule grid in response hours tab
- Light + dark mode
- Mobile (375 px) — tabs become horizontal scroll, vertical-tabs reflow

#### Task 11: PR

```bash
git push -u origin feature/connections-polish
gh pr create --title "feat(ui): deep polish for Connections flow" --body "..."
```

---

## Self-Review
- [x] Every task has exact files + full code where new
- [x] New primitives defined before consumers
- [x] Panel extension non-breaking (all existing tone=default calls unchanged)
- [x] Phase A → B → C ordering correct
- [x] Preserve all form hooks, mutations, save handlers
- [x] No behavioral changes — only chrome/layout
