import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Loader2,
  ChevronLeft,
  ChevronRight,
  Eye,
  AlertTriangle,
  CheckCircle,
} from 'lucide-react';
import { useQAEvaluationLogs } from '@/hooks/useQAInspector';
import { formatDistanceToNow } from 'date-fns';
import { cn } from '@/lib/utils';
import type { QAEvaluationLog, QAEvaluationLogFilters } from '@/types/qa-inspector';

interface QAEvaluationLogListProps {
  botId: number;
  onSelectLog?: (logId: number) => void;
}

export function QAEvaluationLogList({
  botId,
  onSelectLog,
}: QAEvaluationLogListProps) {
  const [filters, setFilters] = useState<QAEvaluationLogFilters>({
    is_flagged: undefined,
    issue_type: undefined,
    page: 1,
    per_page: 20,
  });

  const { data, isLoading, isError } = useQAEvaluationLogs(botId, filters);

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
        Failed to load evaluation logs
      </div>
    );
  }

  const logs = data?.data || [];
  const meta = data?.meta || { current_page: 1, last_page: 1, total: 0, per_page: 20 };

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="flex gap-4 flex-wrap">
        <Select
          value={
            filters.is_flagged === undefined
              ? 'all'
              : filters.is_flagged
                ? 'flagged'
                : 'passed'
          }
          onValueChange={(val) =>
            setFilters((f) => ({
              ...f,
              is_flagged: val === 'all' ? undefined : val === 'flagged',
              page: 1,
            }))
          }
        >
          <SelectTrigger className="w-36">
            <SelectValue placeholder="Status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All</SelectItem>
            <SelectItem value="flagged">Flagged Only</SelectItem>
            <SelectItem value="passed">Passed Only</SelectItem>
          </SelectContent>
        </Select>

        <Select
          value={filters.issue_type || 'all'}
          onValueChange={(val) =>
            setFilters((f) => ({
              ...f,
              issue_type: val === 'all' ? undefined : val,
              page: 1,
            }))
          }
        >
          <SelectTrigger className="w-44">
            <SelectValue placeholder="Issue Type" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All Issue Types</SelectItem>
            <SelectItem value="hallucination">Hallucination</SelectItem>
            <SelectItem value="price_error">Price Error</SelectItem>
            <SelectItem value="wrong_tone">Wrong Tone</SelectItem>
            <SelectItem value="missing_info">Missing Info</SelectItem>
            <SelectItem value="off_topic">Off Topic</SelectItem>
            <SelectItem value="unanswered">Unanswered</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* Table */}
      <div className="border rounded-lg overflow-hidden">
        {/* Table Header */}
        <div className="grid grid-cols-[1fr_80px_100px_120px_2fr_60px] gap-4 px-4 py-3 bg-muted/50 border-b text-sm font-medium text-muted-foreground">
          <div>Time</div>
          <div>Score</div>
          <div>Status</div>
          <div>Issue Type</div>
          <div>Question</div>
          <div className="text-right">Action</div>
        </div>

        {/* Table Body */}
        <div className="divide-y">
          {logs.length > 0 ? (
            logs.map((log: QAEvaluationLog) => (
              <div
                key={log.id}
                className="grid grid-cols-[1fr_80px_100px_120px_2fr_60px] gap-4 px-4 py-3 items-center hover:bg-muted/30 cursor-pointer transition-colors"
                onClick={() => onSelectLog?.(log.id)}
              >
                <div className="text-sm text-muted-foreground">
                  {formatDistanceToNow(new Date(log.created_at), {
                    addSuffix: true,
                  })}
                </div>
                <div>
                  <ScoreBadge score={log.overall_score} />
                </div>
                <div>
                  {log.is_flagged ? (
                    <Badge variant="destructive" className="gap-1">
                      <AlertTriangle className="h-3 w-3" />
                      Flagged
                    </Badge>
                  ) : (
                    <Badge
                      variant="secondary"
                      className="gap-1 bg-green-100 text-green-700"
                    >
                      <CheckCircle className="h-3 w-3" />
                      Passed
                    </Badge>
                  )}
                </div>
                <div>
                  {log.issue_type ? (
                    <Badge variant="outline" className="capitalize">
                      {log.issue_type.replace('_', ' ')}
                    </Badge>
                  ) : (
                    <span className="text-muted-foreground">-</span>
                  )}
                </div>
                <div className="truncate text-sm">{log.user_question}</div>
                <div className="text-right">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={(e) => {
                      e.stopPropagation();
                      onSelectLog?.(log.id);
                    }}
                  >
                    <Eye className="h-4 w-4" />
                  </Button>
                </div>
              </div>
            ))
          ) : (
            <div className="text-center py-8 text-muted-foreground">
              No evaluation logs found
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

function ScoreBadge({ score }: { score: number }) {
  const percentage = Math.round(score * 100);
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
      {percentage}%
    </span>
  );
}

export default QAEvaluationLogList;
