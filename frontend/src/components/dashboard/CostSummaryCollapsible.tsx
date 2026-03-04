import { Banknote } from 'lucide-react';
import { CostAnalytics } from '@/components/analytics/CostAnalytics';
import { CollapsibleCard } from './CollapsibleCard';
import { formatTHB } from '@/lib/currency';

interface CostSummaryCollapsibleProps {
  monthCost?: number;
}

export function CostSummaryCollapsible({ monthCost }: CostSummaryCollapsibleProps) {
  return (
    <CollapsibleCard
      icon={Banknote}
      title="ค่าใช้จ่าย AI"
      summary={`เดือนนี้ ${formatTHB(monthCost ?? 0)}`}
    >
      <CostAnalytics />
    </CollapsibleCard>
  );
}
