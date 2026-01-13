import { useState } from 'react';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Loader2,
  AlertTriangle,
  CheckCircle,
  TrendingUp,
  BarChart3,
} from 'lucide-react';
import { useQAStats } from '@/hooks/useQAInspector';
import { cn } from '@/lib/utils';
import type {
  QAScoreTrendPoint,
  QAIssueBreakdownItem,
  QAMetricAverages,
} from '@/types/qa-inspector';

interface QADashboardProps {
  botId: number;
}

export function QADashboard({ botId }: QADashboardProps) {
  const [period, setPeriod] = useState('7d');
  const { data: stats, isLoading, isError } = useQAStats(botId, period);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center p-8">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (isError || !stats) {
    return (
      <div className="text-center p-8 text-muted-foreground">
        Failed to load dashboard data
      </div>
    );
  }

  const { summary, score_trend, issue_breakdown, metric_averages } = stats;

  return (
    <div className="space-y-6">
      {/* Period Selector */}
      <div className="flex justify-end">
        <Select value={period} onValueChange={setPeriod}>
          <SelectTrigger className="w-32">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="1d">Last 24h</SelectItem>
            <SelectItem value="7d">Last 7 days</SelectItem>
            <SelectItem value="30d">Last 30 days</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* Summary Cards */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <SummaryCard
          title="Total Evaluated"
          value={summary.total_evaluated}
          icon={<BarChart3 className="h-4 w-4" />}
        />
        <SummaryCard
          title="Flagged Issues"
          value={summary.total_flagged}
          icon={<AlertTriangle className="h-4 w-4" />}
          variant={summary.total_flagged > 0 ? 'warning' : 'default'}
        />
        <SummaryCard
          title="Error Rate"
          value={`${summary.error_rate}%`}
          icon={<TrendingUp className="h-4 w-4" />}
          variant={summary.error_rate > 15 ? 'destructive' : 'default'}
        />
        <SummaryCard
          title="Average Score"
          value={`${summary.average_score}%`}
          icon={<CheckCircle className="h-4 w-4" />}
          variant={summary.average_score >= 70 ? 'success' : 'warning'}
        />
      </div>

      {/* Score Trend Chart */}
      <Card>
        <CardHeader>
          <CardTitle>Score Trend</CardTitle>
          <CardDescription>
            Daily average scores over the selected period
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="h-48 flex items-end gap-1">
            {score_trend.length > 0 ? (
              score_trend.map((point: QAScoreTrendPoint, index: number) => (
                <div
                  key={index}
                  className="flex-1 bg-primary/80 hover:bg-primary transition-colors rounded-t cursor-pointer"
                  style={{ height: `${point.average_score}%` }}
                  title={`${point.date}: ${point.average_score}%`}
                />
              ))
            ) : (
              <div className="w-full text-center text-muted-foreground py-8">
                No data for this period
              </div>
            )}
          </div>
          {score_trend.length > 0 && (
            <div className="flex justify-between text-xs text-muted-foreground mt-2">
              <span>{score_trend[0]?.date}</span>
              <span>{score_trend[score_trend.length - 1]?.date}</span>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Issue Breakdown & Metric Averages */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Issue Breakdown */}
        <Card>
          <CardHeader>
            <CardTitle>Issue Breakdown</CardTitle>
            <CardDescription>
              Distribution of flagged issues by type
            </CardDescription>
          </CardHeader>
          <CardContent>
            {issue_breakdown.length > 0 ? (
              <div className="space-y-3">
                {issue_breakdown.map((issue: QAIssueBreakdownItem) => (
                  <div key={issue.type} className="flex items-center gap-3">
                    <div className="flex-1">
                      <div className="flex justify-between text-sm mb-1">
                        <span className="capitalize">
                          {issue.type.replace('_', ' ')}
                        </span>
                        <span className="text-muted-foreground">
                          {issue.count} ({issue.percentage}%)
                        </span>
                      </div>
                      <div className="h-2 bg-muted rounded-full overflow-hidden">
                        <div
                          className="h-full bg-destructive/80 rounded-full"
                          style={{ width: `${issue.percentage}%` }}
                        />
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="text-center text-muted-foreground py-4">
                No flagged issues in this period
              </div>
            )}
          </CardContent>
        </Card>

        {/* Metric Averages */}
        <Card>
          <CardHeader>
            <CardTitle>Metric Averages</CardTitle>
            <CardDescription>Average scores across all 5 metrics</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-3">
              {Object.entries(metric_averages as QAMetricAverages).map(
                ([metric, score]) => (
                  <MetricBar key={metric} name={metric} score={score} />
                )
              )}
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

function SummaryCard({
  title,
  value,
  icon,
  variant = 'default',
}: {
  title: string;
  value: string | number;
  icon: React.ReactNode;
  variant?: 'default' | 'success' | 'warning' | 'destructive';
}) {
  return (
    <Card>
      <CardContent className="pt-6">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm text-muted-foreground">{title}</p>
            <p
              className={cn(
                'text-2xl font-bold',
                variant === 'success' && 'text-green-600',
                variant === 'warning' && 'text-yellow-600',
                variant === 'destructive' && 'text-red-600'
              )}
            >
              {value}
            </p>
          </div>
          <div
            className={cn(
              'p-2 rounded-full',
              variant === 'default' && 'bg-muted',
              variant === 'success' && 'bg-green-100 text-green-600',
              variant === 'warning' && 'bg-yellow-100 text-yellow-600',
              variant === 'destructive' && 'bg-red-100 text-red-600'
            )}
          >
            {icon}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function MetricBar({ name, score }: { name: string; score: number }) {
  const percentage = score * 100;
  const variant =
    percentage >= 70 ? 'success' : percentage >= 50 ? 'warning' : 'destructive';

  return (
    <div>
      <div className="flex justify-between text-sm mb-1">
        <span className="capitalize">{name.replace('_', ' ')}</span>
        <span
          className={cn(
            variant === 'success' && 'text-green-600',
            variant === 'warning' && 'text-yellow-600',
            variant === 'destructive' && 'text-red-600'
          )}
        >
          {percentage.toFixed(0)}%
        </span>
      </div>
      <div className="h-2 bg-muted rounded-full overflow-hidden">
        <div
          className={cn(
            'h-full rounded-full transition-all',
            variant === 'success' && 'bg-green-500',
            variant === 'warning' && 'bg-yellow-500',
            variant === 'destructive' && 'bg-red-500'
          )}
          style={{ width: `${percentage}%` }}
        />
      </div>
    </div>
  );
}

export default QADashboard;
