import { Banknote } from 'lucide-react';
import { formatTHB } from '@/lib/currency';
import { Panel } from '@/components/common';
import type { CostSummary } from '@/types/api';

interface CompactCostBreakdownProps {
  summary: CostSummary;
}

export function CompactCostBreakdown({ summary }: CompactCostBreakdownProps) {
  return (
    <Panel title="ค่า API" icon={Banknote}>
      {/* Row 1: Today, Week, Month */}
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-lg bg-accent/50 p-3">
          <p className="text-xs text-muted-foreground">วันนี้</p>
          <p className="mt-1 text-lg font-bold tabular-nums">{formatTHB(summary.today_cost)}</p>
        </div>
        <div className="rounded-lg bg-accent/50 p-3">
          <p className="text-xs text-muted-foreground">สัปดาห์</p>
          <p className="mt-1 text-lg font-bold tabular-nums">{formatTHB(summary.week_cost)}</p>
        </div>
        <div className="rounded-lg bg-accent/50 p-3">
          <p className="text-xs text-muted-foreground">เดือนนี้</p>
          <p className="mt-1 text-lg font-bold tabular-nums">{formatTHB(summary.month_cost)}</p>
        </div>
      </div>

      {/* Row 2: Total Responses, Average per Response */}
      <div className="mt-4 grid grid-cols-2 gap-4">
        <div className="rounded-lg border border-dashed p-3">
          <p className="text-xs text-muted-foreground">AI ตอบกลับ</p>
          <p className="mt-1 text-lg font-bold tabular-nums">
            {summary.total_responses.toLocaleString()}{' '}
            <span className="text-sm font-normal text-muted-foreground">ครั้ง</span>
          </p>
        </div>
        <div className="rounded-lg border border-dashed p-3">
          <p className="text-xs text-muted-foreground">เฉลี่ย/ตอบ</p>
          <p className="mt-1 text-lg font-bold tabular-nums">
            {formatTHB(summary.avg_cost_per_response)}
          </p>
        </div>
      </div>
    </Panel>
  );
}
