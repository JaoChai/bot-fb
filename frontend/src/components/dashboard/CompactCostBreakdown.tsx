import { Banknote } from 'lucide-react';
import { formatTHB } from '@/lib/currency';
import type { CostSummary } from '@/types/api';

interface CompactCostBreakdownProps {
  summary: CostSummary;
}

export function CompactCostBreakdown({ summary }: CompactCostBreakdownProps) {
  return (
    <div className="rounded-xl border bg-card p-6 shadow-sm">
      <h3 className="mb-4 flex items-center gap-2 text-base font-semibold">
        <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-950/40">
          <Banknote className="h-4 w-4 text-amber-600 dark:text-amber-400" />
        </div>
        ค่า API
      </h3>

      {/* Row 1: Today, Week, Month */}
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-lg bg-accent/50 p-3">
          <p className="text-xs text-muted-foreground">วันนี้</p>
          <p className="mt-1 text-lg font-bold">{formatTHB(summary.today_cost)}</p>
        </div>
        <div className="rounded-lg bg-accent/50 p-3">
          <p className="text-xs text-muted-foreground">สัปดาห์</p>
          <p className="mt-1 text-lg font-bold">{formatTHB(summary.week_cost)}</p>
        </div>
        <div className="rounded-lg bg-accent/50 p-3">
          <p className="text-xs text-muted-foreground">เดือนนี้</p>
          <p className="mt-1 text-lg font-bold">{formatTHB(summary.month_cost)}</p>
        </div>
      </div>

      {/* Row 2: Total Responses, Average per Response */}
      <div className="mt-4 grid grid-cols-2 gap-4">
        <div className="rounded-lg border border-dashed p-3">
          <p className="text-xs text-muted-foreground">AI ตอบกลับ</p>
          <p className="mt-1 text-lg font-bold">{summary.total_responses.toLocaleString()} <span className="text-sm font-normal text-muted-foreground">ครั้ง</span></p>
        </div>
        <div className="rounded-lg border border-dashed p-3">
          <p className="text-xs text-muted-foreground">เฉลี่ย/ตอบ</p>
          <p className="mt-1 text-lg font-bold">{formatTHB(summary.avg_cost_per_response)}</p>
        </div>
      </div>
    </div>
  );
}
