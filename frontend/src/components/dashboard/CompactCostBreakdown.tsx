import { Banknote } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatTHB } from '@/lib/currency';
import type { CostSummary } from '@/types/api';

interface CompactCostBreakdownProps {
  summary: CostSummary;
}

export function CompactCostBreakdown({ summary }: CompactCostBreakdownProps) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-base">
          <Banknote className="h-4 w-4" />
          ค่า API
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {/* Row 1: Today, Week, Month */}
        <div className="grid grid-cols-3 gap-4">
          <div>
            <p className="text-xs text-muted-foreground">วันนี้</p>
            <p className="text-lg font-semibold">{formatTHB(summary.today_cost)}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">สัปดาห์</p>
            <p className="text-lg font-semibold">{formatTHB(summary.week_cost)}</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">เดือนนี้</p>
            <p className="text-lg font-semibold">{formatTHB(summary.month_cost)}</p>
          </div>
        </div>

        {/* Separator */}
        <div className="border-t my-3" />

        {/* Row 2: Total Responses, Average per Response */}
        <div className="grid grid-cols-2 gap-4">
          <div>
            <p className="text-xs text-muted-foreground">AI ตอบกลับ</p>
            <p className="text-lg font-semibold">{summary.total_responses.toLocaleString()} ครั้ง</p>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">เฉลี่ย/ตอบ</p>
            <p className="text-lg font-semibold">{formatTHB(summary.avg_cost_per_response)}</p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
