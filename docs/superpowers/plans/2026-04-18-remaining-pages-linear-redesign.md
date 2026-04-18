# bot-fb Remaining Pages — Linear-Style SaaS Minimal Redesign

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the 8 remaining pages (Dashboard, KnowledgeBase, Settings, Team, VIP, Chat, Orders, Auth + QuickReplies) to match the Linear-style minimal SaaS design language already shipped for Connection pages (PR #130) and Flow Editor (PR #131), while simplifying duplicated patterns.

**Architecture:** The shared primitives (`PageHeader`, `SettingSection`, `SettingRow`, `StickyActionBar`) already live at `@/components/connections/*` and are consumed by flow-editor, bot-settings, and connection pages. We **keep them there** (widely imported — moving would churn ~18 files) and extend the library with three new primitives: `EmptyState`, `StatCard`, `BotSelector`. Each remaining page is then rewritten to adopt these primitives, replacing the ad-hoc `Card` + colored-icon-circle patterns and gradient stat cards with neutral, consistent surfaces. Refactoring happens alongside redesign (not as a separate phase).

**Tech Stack:** React 19 + TypeScript + Tailwind v4 + shadcn/ui + lucide-react. No new deps.

---

## Design Language Rules (applies to every task)

These encode the Linear/Vercel/Stripe aesthetic we shipped in #130/#131:

1. **Page shell:** every page starts with `<div className="space-y-6">` and a `<PageHeader>` — no ad-hoc `<h1 className="text-2xl font-bold">` blocks.
2. **No gradient stat cards, no colored icon circles** (`bg-primary/10 p-2` wrappers). Icons sit flat in `text-muted-foreground` and gain color only for semantic states (destructive, success).
3. **Sections = `SettingSection`** (card-lite surface with header + body) — not `Card` + `CardHeader` + `CardContent`. Reserve `Card` for truly self-contained surfaces (table frames, data grids).
4. **Rows = `SettingRow`** — label+description on the left, control on the right. Use whenever you have a toggle/input/select next to a label.
5. **Buttons:** primary = default variant, destructive stays `variant="destructive"`, secondary = `variant="outline"` or `ghost`. No custom gradient/colored buttons.
6. **Numbers:** always `tabular-nums` for counts, currency, percentages.
7. **Empty states:** `<EmptyState>` primitive — icon, title, description, optional CTA. Flat (no dashed-border cards).
8. **Loading:** `Loader2 className="animate-spin text-muted-foreground"` centered in a padded container. No skeleton unless it already exists.
9. **Hover on clickable rows:** `hover:bg-muted/50 transition-colors` — no shadow lifts, no scale animations.
10. **Spacing rhythm:** sections separated by `space-y-6`, dense rows by `space-y-4`.
11. **Body text:** `text-sm text-muted-foreground` for descriptions under headings. Headings themselves use `text-lg font-medium` for section titles (already the default in `SettingSection`).
12. **Borders over shadows:** surfaces use `border` + solid background. Shadows only on overlays (dialogs, popovers, dropdowns).

---

## File Structure

**New files (3):**
- `frontend/src/components/connections/EmptyState.tsx` — shared empty-state primitive
- `frontend/src/components/connections/StatCard.tsx` — flat metric card (replaces gradient DashboardStatCard usage)
- `frontend/src/components/connections/BotSelector.tsx` — shared bot dropdown (used by Chat/Team/VIP)

**Modified (barrel):**
- `frontend/src/components/connections/index.ts` — export the three new primitives

**Modified pages (9):**
- `frontend/src/pages/DashboardPage.tsx` (169 → ~140 lines)
- `frontend/src/pages/KnowledgeBasePage.tsx` (489 → ~400 lines)
- `frontend/src/pages/SettingsPage.tsx` (296 → ~260 lines)
- `frontend/src/pages/TeamPage.tsx` (395 → ~330 lines)
- `frontend/src/pages/VipManagementPage.tsx` (426 → ~380 lines)
- `frontend/src/pages/ChatPage.tsx` (333 → ~310 lines, bot selector panel + empty states only)
- `frontend/src/pages/OrdersPage.tsx` (24 → ~24 lines, swap header for PageHeader)
- `frontend/src/pages/auth/LoginPage.tsx` (93 → ~93 lines, polish)
- `frontend/src/pages/auth/RegisterPage.tsx` (125 → ~125 lines, polish)
- `frontend/src/pages/settings/QuickRepliesPage.tsx` — deferred to a follow-up; its internal structure is large enough to warrant its own task and the owner-only route is low-traffic

**Modified dashboard components (1):**
- `frontend/src/components/dashboard/DashboardStatCard.tsx` — strip gradient/border-color props, rely on StatCard under the hood OR delete in favour of StatCard.

**Verification pipeline** (run after every task that changes code):
```bash
cd /home/jaochai/code/bot-fb/frontend
npx tsc --noEmit
npm run lint
```
And at phase boundaries:
```bash
npm run build
```

---

## Task 1: Add `EmptyState` primitive

**Files:**
- Create: `frontend/src/components/connections/EmptyState.tsx`
- Modify: `frontend/src/components/connections/index.ts`

- [ ] **Step 1: Write the component**

```tsx
import type { ReactNode, ElementType } from 'react';
import { cn } from '@/lib/utils';

interface EmptyStateProps {
  icon?: ElementType;
  title: string;
  description?: string;
  action?: ReactNode;
  className?: string;
}

export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center rounded-lg border border-dashed bg-muted/20 px-6 py-12 text-center',
        className,
      )}
    >
      {Icon && (
        <div className="mb-4 flex h-10 w-10 items-center justify-center rounded-md border bg-background">
          <Icon className="h-5 w-5 text-muted-foreground" />
        </div>
      )}
      <h3 className="text-base font-medium">{title}</h3>
      {description && (
        <p className="mt-1 max-w-sm text-sm text-muted-foreground">{description}</p>
      )}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}
```

- [ ] **Step 2: Re-export**

Edit `frontend/src/components/connections/index.ts` — append:
```ts
export { EmptyState } from './EmptyState';
```

- [ ] **Step 3: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```
Expected: 0 errors.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/connections/EmptyState.tsx frontend/src/components/connections/index.ts
git commit -m "feat(ui): add EmptyState shared primitive"
```

---

## Task 2: Add `StatCard` primitive

**Files:**
- Create: `frontend/src/components/connections/StatCard.tsx`
- Modify: `frontend/src/components/connections/index.ts`

- [ ] **Step 1: Write the component**

```tsx
import type { ElementType, ReactNode } from 'react';
import { ArrowDown, ArrowUp, Minus } from 'lucide-react';
import { cn } from '@/lib/utils';

type Trend = { value: number; direction: 'up' | 'down' | 'stable' };

interface StatCardProps {
  title: string;
  value: ReactNode;
  description?: ReactNode;
  icon?: ElementType;
  trend?: Trend;
  className?: string;
}

export function StatCard({ title, value, description, icon: Icon, trend, className }: StatCardProps) {
  const TrendIcon = trend?.direction === 'up' ? ArrowUp : trend?.direction === 'down' ? ArrowDown : Minus;
  const trendTone =
    trend?.direction === 'up'
      ? 'text-emerald-600 dark:text-emerald-400'
      : trend?.direction === 'down'
      ? 'text-destructive'
      : 'text-muted-foreground';

  return (
    <div className={cn('rounded-lg border bg-card p-4', className)}>
      <div className="flex items-center justify-between">
        <p className="text-sm font-medium text-muted-foreground">{title}</p>
        {Icon && <Icon className="h-4 w-4 text-muted-foreground" />}
      </div>
      <p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p>
      <div className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
        {trend && (
          <span className={cn('inline-flex items-center gap-0.5', trendTone)}>
            <TrendIcon className="h-3 w-3" />
            {trend.value}%
          </span>
        )}
        {description && <span>{description}</span>}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Re-export**

Append to `frontend/src/components/connections/index.ts`:
```ts
export { StatCard } from './StatCard';
```

- [ ] **Step 3: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/connections/StatCard.tsx frontend/src/components/connections/index.ts
git commit -m "feat(ui): add StatCard shared primitive"
```

---

## Task 3: Add `BotSelector` primitive

**Files:**
- Create: `frontend/src/components/connections/BotSelector.tsx`
- Modify: `frontend/src/components/connections/index.ts`

- [ ] **Step 1: Write the component**

```tsx
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

export interface BotSelectorOption {
  id: number | string;
  name: string;
}

interface BotSelectorProps {
  bots: BotSelectorOption[];
  value?: string | number;
  onChange: (botId: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
}

export function BotSelector({
  bots,
  value,
  onChange,
  placeholder = 'เลือกบอท',
  disabled,
  className,
}: BotSelectorProps) {
  return (
    <Select
      value={value !== undefined ? String(value) : undefined}
      onValueChange={onChange}
      disabled={disabled}
    >
      <SelectTrigger className={cn('w-full', className)}>
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        {bots.map((bot) => (
          <SelectItem key={bot.id} value={String(bot.id)}>
            {bot.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
```

- [ ] **Step 2: Re-export**

Append to `frontend/src/components/connections/index.ts`:
```ts
export { BotSelector } from './BotSelector';
export type { BotSelectorOption } from './BotSelector';
```

- [ ] **Step 3: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/components/connections/BotSelector.tsx frontend/src/components/connections/index.ts
git commit -m "feat(ui): add BotSelector shared primitive"
```

---

## Task 4: Redesign `DashboardPage` (highest visual impact)

**Files:**
- Modify: `frontend/src/pages/DashboardPage.tsx` (full rewrite)
- Modify: `frontend/src/components/dashboard/DashboardStatCard.tsx` (simplified — delegate to StatCard)

- [ ] **Step 1: Simplify `DashboardStatCard` to wrap `StatCard`**

Replace `frontend/src/components/dashboard/DashboardStatCard.tsx` with:
```tsx
import type { ElementType } from 'react';
import { StatCard } from '@/components/connections';

interface Props {
  title: string;
  value: React.ReactNode;
  description?: React.ReactNode;
  icon?: ElementType;
  trend?: { value: number; direction: 'up' | 'down' | 'stable' };
}

export function DashboardStatCard(props: Props) {
  return <StatCard {...props} />;
}
```

(The `className` prop for gradient borders is dropped — Dashboard will stop passing it.)

- [ ] **Step 2: Rewrite `DashboardPage.tsx`**

Replace file with:
```tsx
import { useMemo } from 'react';
import { ShoppingCart, DollarSign, MessageSquare, Banknote } from 'lucide-react';
import { formatTHB, formatBaht } from '@/lib/currency';
import { Button } from '@/components/ui/button';
import { PageHeader } from '@/components/connections';
import { useDashboardSummary } from '@/hooks/useDashboard';
import { useCostAnalytics } from '@/hooks/useCostAnalytics';
import { useOrderSummary, useOrdersByProduct } from '@/hooks/useOrders';
import {
  DashboardStatCard,
  RecentActivityTimeline,
  DashboardSkeleton,
  BotStatusList,
  BusinessHealthBar,
  DualAxisChart,
  CompactCostBreakdown,
  CompactStockToggle,
  RecentOrdersPreview,
  ProductsSummaryCard,
} from '@/components/dashboard';
import { useAuthStore } from '@/stores/authStore';

function calcTrend(today: number, yesterday: number) {
  if (yesterday <= 0) return undefined;
  const pct = ((today - yesterday) / yesterday) * 100;
  return {
    value: Math.abs(Math.round(pct)),
    direction: pct > 0 ? ('up' as const) : pct < 0 ? ('down' as const) : ('stable' as const),
  };
}

export function DashboardPage() {
  const { user } = useAuthStore();
  const { data, isLoading, error } = useDashboardSummary();
  const { data: costData } = useCostAnalytics({ group_by: 'day' });
  const { data: orderData } = useOrderSummary();
  const { data: productsData } = useOrdersByProduct({});

  const today = useMemo(
    () =>
      new Date().toLocaleDateString('th-TH', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }),
    [],
  );

  const header = (
    <PageHeader
      title="แดชบอร์ด"
      description="ภาพรวมธุรกิจของคุณ"
      actions={<span className="text-sm text-muted-foreground tabular-nums">{today}</span>}
    />
  );

  if (isLoading) {
    return (
      <div className="space-y-6">
        {header}
        <DashboardSkeleton />
      </div>
    );
  }

  if (error) {
    return (
      <div className="space-y-6">
        {header}
        <div className="rounded-lg border bg-card p-8 text-center">
          <p className="text-destructive font-medium">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
          <Button variant="outline" className="mt-4" onClick={() => window.location.reload()}>
            ลองใหม่
          </Button>
        </div>
      </div>
    );
  }

  const activities = data?.recent_activity ?? [];
  const revTrend = calcTrend(
    orderData?.summary?.today_revenue ?? 0,
    orderData?.summary?.yesterday_revenue ?? 0,
  );
  const msgTrend = calcTrend(
    data?.summary.messages_today ?? 0,
    data?.summary.messages_yesterday ?? 0,
  );

  return (
    <div className="space-y-6">
      {header}

      <BusinessHealthBar
        bots={data?.bots ?? []}
        alerts={data?.alerts ?? { handover_conversations: [] }}
      />

      <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
        <DashboardStatCard
          title="ยอดขายวันนี้"
          value={formatBaht(orderData?.summary?.today_revenue ?? 0)}
          description={`${orderData?.summary?.today_orders ?? 0} ออเดอร์`}
          icon={ShoppingCart}
          trend={revTrend}
        />
        <DashboardStatCard
          title="ยอดขายเดือนนี้"
          value={formatBaht(orderData?.summary?.this_month_revenue ?? 0)}
          description={`${orderData?.summary?.this_month_orders ?? 0} ออเดอร์`}
          icon={DollarSign}
        />
        <DashboardStatCard
          title="ข้อความวันนี้"
          value={data?.summary.messages_today ?? 0}
          description={`จาก ${data?.summary.total_bots ?? 0} บอท`}
          icon={MessageSquare}
          trend={msgTrend}
        />
        <DashboardStatCard
          title="ค่า API วันนี้"
          value={formatTHB(costData?.summary.today_cost ?? 0)}
          description={`เดือน ${formatTHB(costData?.summary.month_cost ?? 0)}`}
          icon={Banknote}
        />
      </div>

      <DualAxisChart
        orderTimeSeries={orderData?.time_series ?? []}
        costTimeSeries={costData?.time_series ?? []}
        vipCustomers={data?.summary.vip_customers}
        vipTotalSpent={data?.summary.vip_total_spent}
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <BotStatusList bots={data?.bots ?? []} />
        {productsData && productsData.length > 0 && <ProductsSummaryCard products={productsData} />}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {costData?.summary && <CompactCostBreakdown summary={costData.summary} />}
        <div className="space-y-4">
          {user?.role === 'owner' && <CompactStockToggle />}
          <RecentActivityTimeline activities={activities.slice(0, 3)} />
        </div>
      </div>

      <RecentOrdersPreview />
    </div>
  );
}
```

- [ ] **Step 3: Type-check, lint, build**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/DashboardPage.tsx frontend/src/components/dashboard/DashboardStatCard.tsx
git commit -m "refactor(dashboard): Linear-style minimal redesign with flat StatCard"
```

---

## Task 5: Redesign `KnowledgeBasePage`

**Files:**
- Modify: `frontend/src/pages/KnowledgeBasePage.tsx`

The page has two views (list + detail). Redesign both using `PageHeader`, `SettingSection`, `EmptyState`, and remove colored icon circles.

- [ ] **Step 1: Rewrite the page** (full file — see spec below)

Replace `frontend/src/pages/KnowledgeBasePage.tsx` with the version below. Key changes:
- Replace ad-hoc `<div className="flex items-center justify-between">` headers with `<PageHeader>`.
- Replace 4 stat cards in detail view with `StatCard` from shared lib.
- Replace `Card` + `CardHeader` wrappers around Document Upload / Document List / Semantic Search with `SettingSection`.
- Replace "ยังไม่มีฐานความรู้" empty state with `EmptyState`.
- Replace hover-shadow KB grid cards with flat border+hover surfaces.
- Remove colored icon circles (`bg-primary/10 p-2`) — use flat icons.

```tsx
import { useState, useCallback, useMemo } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
  PageHeader,
  SettingSection,
  EmptyState,
  StatCard,
} from '@/components/connections';
import {
  useAllKnowledgeBases,
  useKnowledgeBase,
  useDocuments,
  useCreateKnowledgeBase,
  useDeleteKnowledgeBase,
  useCreateDocument,
  useDeleteDocument,
} from '@/hooks/useKnowledgeBase';
import { DocumentUpload } from '@/components/knowledge-base/DocumentUpload';
import { DocumentList } from '@/components/knowledge-base/DocumentList';
import { SemanticSearch } from '@/components/knowledge-base/SemanticSearch';
import { useKnowledgeBaseChannel } from '@/hooks/useEcho';
import { queryKeys } from '@/lib/query';
import type { DocumentStatusUpdatedEvent } from '@/types/realtime';
import type { Document, PaginatedResponse } from '@/types/api';
import {
  FileText,
  Layers,
  BookOpen,
  Plus,
  Database,
  ArrowLeft,
  Trash2,
  Loader2,
  Calendar,
} from 'lucide-react';

export function KnowledgeBasePage() {
  const [selectedKbId, setSelectedKbId] = useState<number | null>(null);
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [newKbName, setNewKbName] = useState('');
  const [newKbDescription, setNewKbDescription] = useState('');

  const queryClient = useQueryClient();

  const { data: knowledgeBases, isLoading: isLoadingList } = useAllKnowledgeBases();
  const { data: knowledgeBase } = useKnowledgeBase(selectedKbId);
  const {
    data: documentsResponse,
    isLoading: isLoadingDocuments,
    refetch: refetchDocuments,
  } = useDocuments(selectedKbId);
  const documents = documentsResponse?.data ?? [];

  const createKbMutation = useCreateKnowledgeBase();
  const deleteKbMutation = useDeleteKnowledgeBase();
  const createDocMutation = useCreateDocument(selectedKbId);
  const deleteDocMutation = useDeleteDocument(selectedKbId);

  const handleDocumentStatusUpdate = useCallback(
    (event: DocumentStatusUpdatedEvent) => {
      if (!selectedKbId) return;
      const queryKey = [...queryKeys.knowledgeBase.detail(selectedKbId), 'documents'];
      queryClient.setQueryData<PaginatedResponse<Document>>(queryKey, (old) => {
        if (!old) return old;
        return {
          ...old,
          data: old.data.map((doc) =>
            doc.id === event.id
              ? {
                  ...doc,
                  status: event.status,
                  chunk_count: event.chunk_count ?? doc.chunk_count,
                  error_message: event.error_message,
                }
              : doc,
          ),
        };
      });
      if (event.status === 'completed') {
        queryClient.invalidateQueries({
          queryKey: queryKeys.knowledgeBase.detail(selectedKbId),
        });
      }
    },
    [selectedKbId, queryClient],
  );

  const realtimeCallbacks = useMemo(
    () => ({ onDocumentStatusUpdate: handleDocumentStatusUpdate }),
    [handleDocumentStatusUpdate],
  );
  useKnowledgeBaseChannel(selectedKbId, realtimeCallbacks);

  const handleCreateKb = useCallback(async () => {
    if (!newKbName.trim()) return;
    await createKbMutation.mutateAsync({
      name: newKbName.trim(),
      description: newKbDescription.trim() || undefined,
    });
    setNewKbName('');
    setNewKbDescription('');
    setIsCreateDialogOpen(false);
  }, [newKbName, newKbDescription, createKbMutation]);

  const handleDeleteKb = useCallback(async () => {
    if (!selectedKbId) return;
    await deleteKbMutation.mutateAsync(selectedKbId);
    setSelectedKbId(null);
    setIsDeleteDialogOpen(false);
  }, [selectedKbId, deleteKbMutation]);

  const handleUploadDocument = useCallback(
    async (data: { title: string; content: string }) => {
      await createDocMutation.mutateAsync(data);
    },
    [createDocMutation],
  );

  const handleDeleteDocument = useCallback(
    async (documentId: number) => {
      await deleteDocMutation.mutateAsync(documentId);
    },
    [deleteDocMutation],
  );

  // ----- Detail view -----
  if (selectedKbId && knowledgeBase) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={knowledgeBase.name}
          description={knowledgeBase.description ?? undefined}
          backTo={null}
          onBack={() => setSelectedKbId(null)}
          backLabel="กลับ"
          actions={
            <Button variant="outline" size="sm" onClick={() => setIsDeleteDialogOpen(true)}>
              <Trash2 className="h-4 w-4 mr-2" />
              ลบฐานความรู้
            </Button>
          }
        />

        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <StatCard title="เอกสาร" value={knowledgeBase.document_count ?? 0} icon={FileText} />
          <StatCard title="Chunks" value={knowledgeBase.chunk_count ?? 0} icon={Layers} />
          <StatCard
            title="Embedding"
            value={knowledgeBase.embedding_model ?? 'default'}
            icon={Database}
          />
          <StatCard
            title="อัพเดทล่าสุด"
            value={new Date(knowledgeBase.updated_at).toLocaleDateString('th-TH')}
            icon={Calendar}
          />
        </div>

        <SettingSection title="เพิ่มเอกสาร" description="อัพโหลดเนื้อหาเพื่อให้ Bot ใช้ในการตอบคำถาม">
          <DocumentUpload
            onSubmit={handleUploadDocument}
            isSubmitting={createDocMutation.isPending}
          />
        </SettingSection>

        <SettingSection title="เอกสารทั้งหมด" description={`${documents.length} เอกสาร`}>
          <DocumentList
            documents={documents}
            isLoading={isLoadingDocuments}
            isDeleting={deleteDocMutation.isPending}
            onDelete={handleDeleteDocument}
            onRefresh={refetchDocuments}
          />
        </SettingSection>

        <SettingSection title="ทดสอบการค้นหา" description="ค้นหาเนื้อหาในฐานความรู้ด้วย Semantic Search">
          <SemanticSearch
            kbId={selectedKbId}
            hasChunks={(knowledgeBase.chunk_count ?? 0) > 0}
          />
        </SettingSection>

        <AlertDialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>ยืนยันการลบฐานความรู้</AlertDialogTitle>
              <AlertDialogDescription>
                คุณแน่ใจหรือไม่ที่จะลบ "{knowledgeBase.name}"?
                การดำเนินการนี้ไม่สามารถย้อนกลับได้ และจะลบเอกสารทั้งหมดในฐานความรู้นี้ด้วย
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
              <AlertDialogAction
                onClick={handleDeleteKb}
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                disabled={deleteKbMutation.isPending}
              >
                {deleteKbMutation.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                ) : (
                  <Trash2 className="h-4 w-4 mr-2" />
                )}
                ลบ
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>
    );
  }

  // ----- List view -----
  return (
    <div className="space-y-6">
      <PageHeader
        title="ฐานความรู้"
        description="จัดการฐานความรู้สำหรับ Bot ของคุณ"
        actions={
          <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
            <DialogTrigger asChild>
              <Button>
                <Plus className="h-4 w-4 mr-2" />
                สร้างฐานความรู้
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>สร้างฐานความรู้ใหม่</DialogTitle>
                <DialogDescription>
                  กรอกข้อมูลเพื่อสร้างฐานความรู้สำหรับเก็บเอกสารและข้อมูลของคุณ
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="kb-name">ชื่อ *</Label>
                  <Input
                    id="kb-name"
                    placeholder="เช่น คู่มือผลิตภัณฑ์, FAQ"
                    value={newKbName}
                    onChange={(e) => setNewKbName(e.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="kb-description">คำอธิบาย</Label>
                  <Textarea
                    id="kb-description"
                    placeholder="อธิบายเกี่ยวกับฐานความรู้นี้..."
                    value={newKbDescription}
                    onChange={(e) => setNewKbDescription(e.target.value)}
                    rows={3}
                  />
                </div>
              </div>
              <DialogFooter>
                <Button variant="outline" onClick={() => setIsCreateDialogOpen(false)}>
                  ยกเลิก
                </Button>
                <Button
                  onClick={handleCreateKb}
                  disabled={!newKbName.trim() || createKbMutation.isPending}
                >
                  {createKbMutation.isPending ? (
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  ) : (
                    <Plus className="h-4 w-4 mr-2" />
                  )}
                  สร้าง
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        }
      />

      {isLoadingList && (
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      )}

      {!isLoadingList && (!knowledgeBases || knowledgeBases.length === 0) && (
        <EmptyState
          icon={BookOpen}
          title="ยังไม่มีฐานความรู้"
          description="สร้างฐานความรู้เพื่อเก็บเอกสารและข้อมูลที่ Bot จะใช้ในการตอบคำถาม"
          action={
            <Button onClick={() => setIsCreateDialogOpen(true)}>
              <Plus className="h-4 w-4 mr-2" />
              สร้างฐานความรู้แรก
            </Button>
          }
        />
      )}

      {!isLoadingList && knowledgeBases && knowledgeBases.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {knowledgeBases.map((kb) => (
            <button
              key={kb.id}
              onClick={() => setSelectedKbId(kb.id)}
              className="group flex flex-col rounded-lg border bg-card p-4 text-left transition-colors hover:bg-muted/30"
            >
              <div className="flex items-center gap-2">
                <BookOpen className="h-4 w-4 text-muted-foreground" />
                <h3 className="font-medium">{kb.name}</h3>
              </div>
              {kb.description && (
                <p className="mt-1 text-sm text-muted-foreground line-clamp-2">{kb.description}</p>
              )}
              <div className="mt-3 flex items-center gap-2">
                <Badge variant="secondary" className="text-xs tabular-nums">
                  <FileText className="h-3 w-3 mr-1" />
                  {kb.document_count}
                </Badge>
                <Badge variant="outline" className="text-xs tabular-nums">
                  <Layers className="h-3 w-3 mr-1" />
                  {kb.chunk_count}
                </Badge>
              </div>
              <p className="mt-3 text-xs text-muted-foreground">
                อัพเดท {new Date(kb.updated_at).toLocaleDateString('th-TH')}
              </p>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
```

**NOTE on `PageHeader` API:** we rely on `title`, `description`, `actions`, `onBack`, `backLabel`, `backTo` props. If the actual `PageHeader` signature differs, inspect `frontend/src/components/connections/PageHeader.tsx` first and adapt the call sites. Do not add new props to `PageHeader` — use an inline wrapper if needed.

- [ ] **Step 2: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/KnowledgeBasePage.tsx
git commit -m "refactor(kb): Linear-style minimal redesign with shared primitives"
```

---

## Task 6: Redesign `SettingsPage`

**Files:**
- Modify: `frontend/src/pages/SettingsPage.tsx`

Replace `Card`+`CardHeader` sections with `SettingSection`. Remove `Separator` between cards (SettingSection already has border). Remove colored icon circles around Key/Zap.

- [ ] **Step 1: Rewrite**

Key changes (apply as an Edit over the current file — the skeleton below shows target shape):

```tsx
import { useState, useEffect } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { PageHeader, SettingSection, SettingRow } from '@/components/connections';
import {
  Key, Eye, EyeOff, ExternalLink, Loader2, CheckCircle, XCircle, AlertCircle, Zap, ChevronRight,
} from 'lucide-react';
import { Link } from 'react-router';
import {
  useUserSettings, useUpdateOpenRouterSettings, useTestOpenRouterConnection, useClearOpenRouterKey,
} from '@/hooks/useUserSettings';
import { toast } from 'sonner';

export function SettingsPage() {
  // ... (keep existing state/handlers as-is — no business logic changes) ...

  return (
    <div className="space-y-6">
      <PageHeader title="ตั้งค่า" description="จัดการการตั้งค่าบัญชีและ API Keys" />

      <SettingSection
        title="OpenRouter API Key"
        description="ใช้สำหรับสร้าง embeddings ในฐานความรู้"
        icon={Key}
        headerAction={
          <Badge variant={isConfigured ? 'default' : 'secondary'}>
            {isConfigured ? (
              <><CheckCircle className="h-3 w-3 mr-1" /> ตั้งค่าแล้ว</>
            ) : (
              <><AlertCircle className="h-3 w-3 mr-1" /> ยังไม่ได้ตั้งค่า</>
            )}
          </Badge>
        }
      >
        {/* ... same inner markup as before (key display + input + buttons) ... */}
      </SettingSection>

      {user?.role === 'owner' && (
        <Link
          to="/settings/quick-replies"
          className="flex items-center justify-between rounded-lg border bg-card p-4 transition-colors hover:bg-muted/30"
        >
          <div className="flex items-center gap-3">
            <Zap className="h-4 w-4 text-muted-foreground" />
            <div>
              <p className="font-medium">Quick Replies</p>
              <p className="text-sm text-muted-foreground">จัดการคำตอบสำเร็จรูปสำหรับทีม</p>
            </div>
          </div>
          <ChevronRight className="h-4 w-4 text-muted-foreground" />
        </Link>
      )}

      <SettingSection title="โปรไฟล์" description="ข้อมูลส่วนตัวของคุณ">
        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="name">ชื่อ</Label>
            <Input id="name" defaultValue={user?.name || ''} disabled />
          </div>
          <div className="space-y-2">
            <Label htmlFor="email">อีเมล</Label>
            <Input id="email" type="email" defaultValue={user?.email || ''} disabled />
            <p className="text-xs text-muted-foreground">ติดต่อ support เพื่อเปลี่ยนอีเมล</p>
          </div>
        </div>
      </SettingSection>

      <SettingSection
        title="Danger Zone"
        description="การดำเนินการที่ไม่สามารถย้อนกลับได้"
        tone="destructive"
      >
        <Button variant="destructive" disabled>ลบบัญชี</Button>
      </SettingSection>
    </div>
  );
}
```

**Implementation note:** `SettingSection` may not currently support `icon`, `headerAction`, or `tone` props. **Inspect `frontend/src/components/connections/SettingSection.tsx` first.** If those props are missing, either (a) pass the icon/action via `title` ReactNode if supported, or (b) fall back to a plain `<div className="rounded-lg border bg-card p-6">` wrapper for those sections rather than extending the primitive in this task. Do NOT add new props to SettingSection here — that's a separate refactor.

- [ ] **Step 2: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/SettingsPage.tsx
git commit -m "refactor(settings): Linear-style minimal redesign with SettingSection"
```

---

## Task 7: Redesign `TeamPage`

**Files:**
- Modify: `frontend/src/pages/TeamPage.tsx`

- [ ] **Step 1: Apply these transformations**

1. Remove `container max-w-4xl py-6` wrapper — use `space-y-6` to match other pages (RootLayout already provides container).
2. Replace ad-hoc `<div>` header with `<PageHeader title="จัดการทีม" description="เพิ่มและจัดการ Admin สำหรับแต่ละ Bot" />`.
3. Replace "เลือก Bot" `Card` with a bare `BotSelector` at the top of the content area (no card wrapper — just the select with a label).
4. Replace each `Card` (`Add Admin`, `Admin List`, `Auto-Assignment`) with `SettingSection`.
5. Remove `Bot`, `Users`, `UserPlus`, `Settings2` icon-circles in section headers (SettingSection should hold the title text alone).
6. Admin list rows: replace `<div className="flex items-center justify-between p-3 rounded-lg border">` with `SettingRow`-style markup — or keep bordered rows but drop the colored hover.
7. Wrap the "ไม่มี Admin" empty state in `EmptyState`.
8. Auto-Assignment toggle row → `SettingRow`.

- [ ] **Step 2: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/TeamPage.tsx
git commit -m "refactor(team): Linear-style minimal redesign with shared primitives"
```

---

## Task 8: Redesign `VipManagementPage`

**Files:**
- Modify: `frontend/src/pages/VipManagementPage.tsx`

- [ ] **Step 1: Apply these transformations**

1. Replace ad-hoc header with `<PageHeader>` — pass `BotSelector` + `Add VIP` button into `actions`.
2. Replace 3 summary `Card`s with 3 `StatCard`s (no colored numbers — keep `text-foreground`). Use `tabular-nums`.
3. Keep the table wrapped in a `Card` (table needs the frame for overflow-x).
4. Replace empty/error states with `EmptyState` where appropriate.
5. Remove `text-amber-700 dark:text-amber-500` + `text-purple-700 dark:text-purple-400` from stat values — neutral foreground.
6. Remove `Card` wrapper around "เลือกบอทเพื่อดูรายการ VIP" — use `EmptyState`.

- [ ] **Step 2: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/VipManagementPage.tsx
git commit -m "refactor(vip): Linear-style minimal redesign with StatCard + EmptyState"
```

---

## Task 9: Polish `ChatPage`

**Files:**
- Modify: `frontend/src/pages/ChatPage.tsx`

- [ ] **Step 1: Apply targeted tweaks** (this page has a full-height 3-column layout that we keep; only chrome gets polished)

1. Replace the `Select` inside the left panel bot-selector with the new `<BotSelector>` primitive (keep the reset button below it).
2. Replace the "Select a Bot" empty state (lines 178-206) with `<EmptyState icon={MessageSquare} title="Select a Bot" description="Choose a bot to view conversations" action={...} />`.
3. Replace the "Select a conversation" center-panel empty state (lines 292-301) with `<EmptyState icon={MessageSquare} title="Select a conversation" description="Choose a conversation from the list to start chatting" />`.
4. Keep everything else.

- [ ] **Step 2: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/ChatPage.tsx
git commit -m "refactor(chat): adopt shared BotSelector and EmptyState primitives"
```

---

## Task 10: Redesign `OrdersPage`

**Files:**
- Modify: `frontend/src/pages/OrdersPage.tsx`

- [ ] **Step 1: Swap header**

Replace file with:

```tsx
import { useMemo } from 'react';
import { PageHeader } from '@/components/connections';
import { OrdersAnalytics } from '@/components/analytics/OrdersAnalytics';

export function OrdersPage() {
  const today = useMemo(
    () =>
      new Date().toLocaleDateString('th-TH', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }),
    [],
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title="ออเดอร์"
        actions={<span className="text-sm text-muted-foreground tabular-nums">{today}</span>}
      />
      <OrdersAnalytics />
    </div>
  );
}
```

- [ ] **Step 2: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 3: Commit**

```bash
git add frontend/src/pages/OrdersPage.tsx
git commit -m "refactor(orders): adopt PageHeader for consistency"
```

---

## Task 11: Polish auth pages (Login, Register)

**Files:**
- Modify: `frontend/src/pages/auth/LoginPage.tsx`
- Modify: `frontend/src/pages/auth/RegisterPage.tsx`

- [ ] **Step 1: Login polish** — ensure the error alert uses `rounded-md border border-destructive/30 bg-destructive/5` (flat, not solid `bg-destructive/10`). Button style already fine. Remove `lg:text-left` / `lg:*` leftover asymmetry unless used intentionally — verify against `AuthLayout.tsx` design before touching.

- [ ] **Step 2: Register polish** — same treatment as Login. Match exact styling of Login for form field spacing/label sizes.

- [ ] **Step 3: Type-check & lint**

```bash
cd frontend && npx tsc --noEmit && npm run lint
```

- [ ] **Step 4: Commit**

```bash
git add frontend/src/pages/auth/LoginPage.tsx frontend/src/pages/auth/RegisterPage.tsx
git commit -m "refactor(auth): align error states and form spacing with design system"
```

**Skip entirely if `AuthLayout` already provides a consistent, polished shell** (read it first) — auth pages are small and the current look may already match the aesthetic.

---

## Task 12: Final build verification & knip scan

- [ ] **Step 1: Production build**

```bash
cd /home/jaochai/code/bot-fb/frontend
npm run build
```
Expected: completes with 0 errors. Any TS error from `verbatimModuleSyntax` must be fixed immediately (see memory 83 — `import type` for type-only imports).

- [ ] **Step 2: Dead code scan**

```bash
npx knip --reporter compact
```
Expected: no new dead exports introduced. If old props on `DashboardStatCard` (like `className` gradient props) are flagged as unused, remove them from the component signature.

- [ ] **Step 3: Lint**

```bash
npm run lint
```
Expected: 0 errors, 0 warnings.

- [ ] **Step 4: Manual browser verification**

Start dev server (`npm run dev`) and click through each redesigned page in both light & dark mode. Verify:
- [ ] No layout shift on load
- [ ] Empty states display correctly (e.g. new account with no KBs, no bots)
- [ ] Stat cards align across dashboard / KB detail / VIP
- [ ] Sidebar/Header still works (untouched in this plan)
- [ ] No console errors

- [ ] **Step 5: Push & open PR**

```bash
git push -u origin feature/pages-linear-redesign
gh pr create --title "feat(ui): Linear-style minimal redesign for remaining pages" --body "$(cat <<'EOF'
## Summary
- Extend shared `@/components/connections` library with `EmptyState`, `StatCard`, `BotSelector` primitives
- Redesign Dashboard, KnowledgeBase, Settings, Team, VIP, Chat (polish), Orders (polish), Auth (polish) to match the Linear-style minimal SaaS design language already shipped for Connection pages (#130) and Flow Editor (#131)
- Replace gradient stat cards, colored icon circles, and ad-hoc `Card+CardHeader` wrappers with `SettingSection` and flat surfaces
- Simplify `DashboardStatCard` to a thin wrapper around the shared `StatCard`

## Test plan
- [ ] `npm run build` passes (0 TS errors)
- [ ] `npx knip --reporter compact` clean
- [ ] `npm run lint` clean
- [ ] Manual click-through of all pages in light + dark mode
- [ ] Verify empty states, loading states, error states
- [ ] Verify existing functionality unchanged (no behavioral diff)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Out of Scope (follow-up plans if needed)

- `frontend/src/pages/settings/QuickRepliesPage.tsx` (553 lines) — owner-only, deserves a focused redesign
- `Sidebar.tsx` / `Header.tsx` / `MobileNav.tsx` layout shell polish
- Extracting `SettingSection` props (`icon`, `tone`, `headerAction`) — only if Task 6 finds a genuine need
- Visual-regression test suite setup (Playwright + Percy/Chromatic)
- i18n audit (mixed Thai/English currently — out of design scope)

---

## Self-Review Checklist

- [x] Every task lists exact files + line ranges where applicable
- [x] No "TODO" / "fill in later" placeholders
- [x] Shared primitives defined before consumers use them (Tasks 1-3 precede 4-11)
- [x] Commit messages follow `feat:` / `refactor:` convention
- [x] Verification pipeline defined (tsc + lint per task, build + knip at end)
- [x] Design language rules listed once at top, referenced by every task
- [x] Out-of-scope items called out explicitly
- [x] Minimal Change Principle honored — no feature additions, no behavior changes, only chrome
