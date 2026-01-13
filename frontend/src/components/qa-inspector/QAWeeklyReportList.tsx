import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Loader2,
  Eye,
  RefreshCw,
  TrendingUp,
  TrendingDown,
  Minus,
  Plus,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';
import {
  useQAWeeklyReports,
  useGenerateReport,
} from '@/hooks/useQAInspector';
import { cn } from '@/lib/utils';
import type { QAWeeklyReport, QAWeeklyReportFilters } from '@/types/qa-inspector';

interface QAWeeklyReportListProps {
  botId: number;
  onSelectReport?: (reportId: number) => void;
}

export function QAWeeklyReportList({
  botId,
  onSelectReport,
}: QAWeeklyReportListProps) {
  const [filters, setFilters] = useState<QAWeeklyReportFilters>({
    page: 1,
    per_page: 10,
  });

  const { data, isLoading, isError, refetch } = useQAWeeklyReports(
    botId,
    filters
  );
  const generateMutation = useGenerateReport(botId);

  const handleGenerate = () => {
    generateMutation.mutate(undefined);
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center p-8">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="text-center p-8 text-muted-foreground">
        Failed to load weekly reports
      </div>
    );
  }

  const reports = data?.data || [];
  const meta = data?.meta || {
    current_page: 1,
    last_page: 1,
    total: 0,
    per_page: 10,
  };

  return (
    <div className="space-y-4">
      {/* Actions */}
      <div className="flex justify-between items-center">
        <p className="text-sm text-muted-foreground">
          {meta.total} report(s) available
        </p>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => refetch()}>
            <RefreshCw className="h-4 w-4 mr-2" />
            Refresh
          </Button>
          <Button
            size="sm"
            onClick={handleGenerate}
            disabled={generateMutation.isPending}
          >
            {generateMutation.isPending ? (
              <Loader2 className="h-4 w-4 mr-2 animate-spin" />
            ) : (
              <Plus className="h-4 w-4 mr-2" />
            )}
            Generate Report
          </Button>
        </div>
      </div>

      {/* Table */}
      <div className="border rounded-lg overflow-hidden">
        {/* Table Header */}
        <div className="grid grid-cols-[1fr_100px_100px_100px_80px_80px_60px] gap-4 px-4 py-3 bg-muted/50 border-b text-sm font-medium text-muted-foreground">
          <div>Week</div>
          <div>Status</div>
          <div className="text-right">Conversations</div>
          <div className="text-right">Flagged</div>
          <div className="text-right">Avg Score</div>
          <div className="text-right">Trend</div>
          <div className="text-right">Action</div>
        </div>

        {/* Table Body */}
        <div className="divide-y">
          {reports.length > 0 ? (
            reports.map((report: QAWeeklyReport) => (
              <div
                key={report.id}
                className="grid grid-cols-[1fr_100px_100px_100px_80px_80px_60px] gap-4 px-4 py-3 items-center hover:bg-muted/30 cursor-pointer transition-colors"
                onClick={() =>
                  report.status === 'completed' && onSelectReport?.(report.id)
                }
              >
                <div>
                  <p className="font-medium">
                    {formatWeekRange(report.week_start, report.week_end)}
                  </p>
                </div>
                <div>
                  <StatusBadge status={report.status} />
                </div>
                <div className="text-right">{report.total_conversations}</div>
                <div className="text-right">
                  <span
                    className={
                      report.total_flagged > 0 ? 'text-destructive' : ''
                    }
                  >
                    {report.total_flagged}
                  </span>
                </div>
                <div className="text-right">
                  <ScoreBadge score={report.average_score} />
                </div>
                <div className="text-right">
                  <TrendIndicator
                    current={report.average_score}
                    previous={report.previous_average_score}
                  />
                </div>
                <div className="text-right">
                  <Button
                    variant="ghost"
                    size="sm"
                    disabled={report.status !== 'completed'}
                    onClick={(e) => {
                      e.stopPropagation();
                      onSelectReport?.(report.id);
                    }}
                  >
                    <Eye className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            ))
          ) : (
            <div className="text-center py-8 text-muted-foreground">
              No weekly reports yet. Click "Generate Report" to create one.
            </div>
          )}
        </div>
      </div>

      {/* Pagination */}
      {meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-muted-foreground">
            Showing {(meta.current_page - 1) * meta.per_page + 1} -{' '}
            {Math.min(meta.current_page * meta.per_page, meta.total)} of{' '}
            {meta.total}
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page <= 1}
              onClick={() =>
                setFilters((f) => ({ ...f, page: (f.page || 1) - 1 }))
              }
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={meta.current_page >= meta.last_page}
              onClick={() =>
                setFilters((f) => ({ ...f, page: (f.page || 1) + 1 }))
              }
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

function formatWeekRange(start: string, end: string): string {
  const startDate = new Date(start);
  const endDate = new Date(end);

  const formatOptions: Intl.DateTimeFormatOptions = {
    month: 'short',
    day: 'numeric',
  };
  return `${startDate.toLocaleDateString('en-US', formatOptions)} - ${endDate.toLocaleDateString('en-US', formatOptions)}`;
}

function StatusBadge({ status }: { status: string }) {
  const variants: Record<string, 'default' | 'secondary' | 'destructive'> = {
    completed: 'default',
    generating: 'secondary',
    failed: 'destructive',
  };

  return (
    <Badge variant={variants[status] || 'secondary'} className="capitalize">
      {status === 'generating' && (
        <Loader2 className="h-3 w-3 mr-1 animate-spin" />
      )}
      {status}
    </Badge>
  );
}

function ScoreBadge({ score }: { score: number }) {
  // Handle score as either 0-1 or 0-100
  const percentage = score > 1 ? score : score * 100;
  const variant =
    percentage >= 70 ? 'success' : percentage >= 50 ? 'warning' : 'destructive';

  return (
    <span
      className={cn(
        'font-medium',
        variant === 'success' && 'text-green-600',
        variant === 'warning' && 'text-yellow-600',
        variant === 'destructive' && 'text-red-600'
      )}
    >
      {percentage.toFixed(1)}%
    </span>
  );
}

function TrendIndicator({
  current,
  previous,
}: {
  current: number;
  previous: number | null;
}) {
  if (previous === null) {
    return <span className="text-muted-foreground">-</span>;
  }

  // Normalize both values to percentage
  const currentPct = current > 1 ? current : current * 100;
  const previousPct = previous > 1 ? previous : previous * 100;
  const diff = currentPct - previousPct;

  if (Math.abs(diff) < 0.5) {
    return <Minus className="h-4 w-4 text-muted-foreground inline" />;
  }

  if (diff > 0) {
    return (
      <span className="text-green-600 flex items-center justify-end gap-1">
        <TrendingUp className="h-4 w-4" />
        +{diff.toFixed(1)}
      </span>
    );
  }

  return (
    <span className="text-red-600 flex items-center justify-end gap-1">
      <TrendingDown className="h-4 w-4" />
      {diff.toFixed(1)}
    </span>
  );
}

export default QAWeeklyReportList;
