import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Loader2,
  ArrowLeft,
  AlertTriangle,
  Lightbulb,
  BarChart3,
  FileText,
} from 'lucide-react';
import { useQAWeeklyReport } from '@/hooks/useQAInspector';
import { useFlows } from '@/hooks/useFlows';
import { cn } from '@/lib/utils';
import { PromptSuggestionCard } from './PromptSuggestionCard';
import type { QATopIssue } from '@/types/qa-inspector';

interface QAWeeklyReportViewProps {
  botId: number;
  reportId: number;
  onBack?: () => void;
}

export function QAWeeklyReportView({
  botId,
  reportId,
  onBack,
}: QAWeeklyReportViewProps) {
  const { data: report, isLoading, isError, refetch } = useQAWeeklyReport(botId, reportId);
  const { data: flowsData, isLoading: flowsLoading } = useFlows(botId);

  // Transform flows to the format needed by PromptSuggestionCard
  const flows = (flowsData?.data || []).map((flow) => ({
    id: flow.id,
    name: flow.name,
  }));

  if (isLoading || flowsLoading) {
    return (
      <div className="flex items-center justify-center p-8">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (isError || !report) {
    return (
      <div className="text-center p-8 text-muted-foreground">
        ไม่สามารถโหลดรายงานได้
      </div>
    );
  }

  const { performance_summary, top_issues, prompt_suggestions } = report;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-4">
        {onBack && (
          <Button variant="ghost" size="sm" onClick={onBack}>
            <ArrowLeft className="h-4 w-4 mr-2" />
            กลับ
          </Button>
        )}
        <div className="flex-1">
          <h2 className="text-xl font-semibold">
            รายงานประจำสัปดาห์: {formatDate(report.week_start)} ถึง{' '}
            {formatDate(report.week_end)}
          </h2>
          <p className="text-sm text-muted-foreground">
            สร้างเมื่อ{' '}
            {report.generated_at
              ? new Date(report.generated_at).toLocaleString('th-TH')
              : 'ไม่ระบุ'}
          </p>
        </div>
        <Badge variant={report.status === 'completed' ? 'default' : 'secondary'}>
          {report.status === 'completed' ? 'เสร็จสิ้น' : report.status === 'generating' ? 'กำลังสร้าง' : 'ล้มเหลว'}
        </Badge>
      </div>

      {/* Performance Summary */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <BarChart3 className="h-5 w-5" />
            สรุปประสิทธิภาพ
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <SummaryCard
              label="ประเมินทั้งหมด"
              value={performance_summary?.total_evaluated || 0}
            />
            <SummaryCard
              label="พบปัญหา"
              value={performance_summary?.total_flagged || 0}
              variant={
                (performance_summary?.total_flagged || 0) > 0
                  ? 'warning'
                  : 'default'
              }
            />
            <SummaryCard
              label="อัตราข้อผิดพลาด"
              value={`${(performance_summary?.error_rate || 0).toFixed(1)}%`}
              variant={
                (performance_summary?.error_rate || 0) > 15
                  ? 'destructive'
                  : 'default'
              }
            />
            <SummaryCard
              label="คะแนนเฉลี่ย"
              value={`${normalizeScore(performance_summary?.average_score || 0).toFixed(1)}%`}
              variant={
                normalizeScore(performance_summary?.average_score || 0) >= 70
                  ? 'success'
                  : 'warning'
              }
            />
          </div>

          {/* Score Distribution */}
          {performance_summary?.score_distribution && (
            <ScoreDistribution distribution={performance_summary.score_distribution} />
          )}

          {/* Metric Averages */}
          {performance_summary?.metric_averages && (
            <div className="mt-4 pt-4 border-t">
              <p className="text-sm font-medium mb-3">คะแนนแยกตามเกณฑ์</p>
              <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
                {Object.entries(performance_summary.metric_averages).map(
                  ([metric, value]) => (
                    <MetricCard key={metric} metric={metric} value={value} />
                  )
                )}
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Top Issues */}
      {top_issues && top_issues.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <AlertTriangle className="h-5 w-5 text-destructive" />
              ปัญหาหลัก
            </CardTitle>
            <CardDescription>
              ปัญหาที่พบบ่อยที่สุดในสัปดาห์นี้
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-4">
              {top_issues.map((issue: QATopIssue, idx: number) => (
                <IssueCard key={idx} issue={issue} />
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Prompt Suggestions */}
      {prompt_suggestions && prompt_suggestions.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Lightbulb className="h-5 w-5 text-yellow-500" />
              ข้อเสนอแนะการปรับปรุง Prompt
            </CardTitle>
            <CardDescription>
              ข้อเสนอแนะจาก AI เพื่อแก้ไขปัญหาที่พบ กด "นำไปใช้" เพื่อใช้ข้อเสนอแนะ
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="space-y-6">
              {prompt_suggestions.map((suggestion, idx) => (
                <PromptSuggestionCard
                  key={idx}
                  suggestion={suggestion}
                  suggestionIndex={idx}
                  botId={botId}
                  reportId={reportId}
                  flows={flows}
                  onApplySuccess={() => refetch()}
                />
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      {/* Report Metadata */}
      {report.generation_cost !== null && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <FileText className="h-5 w-5" />
              ข้อมูลรายงาน
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
              <div>
                <p className="text-muted-foreground">ค่าใช้จ่ายในการสร้าง</p>
                <p className="font-medium">${report.generation_cost.toFixed(4)}</p>
              </div>
              <div>
                <p className="text-muted-foreground">สนทนาทั้งหมด</p>
                <p className="font-medium">{report.total_conversations}</p>
              </div>
              <div>
                <p className="text-muted-foreground">พบปัญหา</p>
                <p className="font-medium">{report.total_flagged}</p>
              </div>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

// Helper function to normalize score (handle 0-1 and 0-100 formats)
function normalizeScore(score: number): number {
  return score > 1 ? score : score * 100;
}

// Format date to readable string
function formatDate(dateStr: string): string {
  const date = new Date(dateStr);
  return date.toLocaleDateString('th-TH', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  });
}

function SummaryCard({
  label,
  value,
  variant = 'default',
}: {
  label: string;
  value: string | number;
  variant?: 'default' | 'success' | 'warning' | 'destructive';
}) {
  return (
    <div className="text-center p-4 border rounded-lg">
      <p className="text-sm text-muted-foreground mb-1">{label}</p>
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
  );
}

function ScoreDistribution({
  distribution,
}: {
  distribution: { excellent: number; good: number; needs_improvement: number; poor: number };
}) {
  const total =
    distribution.excellent +
    distribution.good +
    distribution.needs_improvement +
    distribution.poor;

  if (total === 0) return null;

  return (
    <div className="mt-4">
      <p className="text-sm font-medium mb-2">การกระจายคะแนน</p>
      <div className="flex gap-1 h-8 rounded overflow-hidden">
        {distribution.excellent > 0 && (
          <div
            className="bg-green-500 flex items-center justify-center text-xs text-white font-medium"
            style={{ width: `${(distribution.excellent / total) * 100}%` }}
            title={`ดีเยี่ยม: ${distribution.excellent}`}
          >
            {distribution.excellent > 0 && distribution.excellent}
          </div>
        )}
        {distribution.good > 0 && (
          <div
            className="bg-blue-500 flex items-center justify-center text-xs text-white font-medium"
            style={{ width: `${(distribution.good / total) * 100}%` }}
            title={`ดี: ${distribution.good}`}
          >
            {distribution.good > 0 && distribution.good}
          </div>
        )}
        {distribution.needs_improvement > 0 && (
          <div
            className="bg-yellow-500 flex items-center justify-center text-xs text-white font-medium"
            style={{ width: `${(distribution.needs_improvement / total) * 100}%` }}
            title={`ต้องปรับปรุง: ${distribution.needs_improvement}`}
          >
            {distribution.needs_improvement > 0 && distribution.needs_improvement}
          </div>
        )}
        {distribution.poor > 0 && (
          <div
            className="bg-red-500 flex items-center justify-center text-xs text-white font-medium"
            style={{ width: `${(distribution.poor / total) * 100}%` }}
            title={`ไม่ผ่าน: ${distribution.poor}`}
          >
            {distribution.poor > 0 && distribution.poor}
          </div>
        )}
      </div>
      <div className="flex gap-4 mt-2 text-xs text-muted-foreground">
        <span className="flex items-center gap-1">
          <span className="w-3 h-3 rounded bg-green-500" />
          ดีเยี่ยม (90-100)
        </span>
        <span className="flex items-center gap-1">
          <span className="w-3 h-3 rounded bg-blue-500" />
          ดี (70-89)
        </span>
        <span className="flex items-center gap-1">
          <span className="w-3 h-3 rounded bg-yellow-500" />
          ต้องปรับปรุง (50-69)
        </span>
        <span className="flex items-center gap-1">
          <span className="w-3 h-3 rounded bg-red-500" />
          ไม่ผ่าน (0-49)
        </span>
      </div>
    </div>
  );
}

function MetricCard({ metric, value }: { metric: string; value: number }) {
  const percentage = normalizeScore(value);
  const variant =
    percentage >= 70 ? 'success' : percentage >= 50 ? 'warning' : 'destructive';

  return (
    <div className="text-center p-2 border rounded">
      <p className="text-xs text-muted-foreground capitalize mb-1">
        {metric.replace(/_/g, ' ')}
      </p>
      <p
        className={cn(
          'text-lg font-bold',
          variant === 'success' && 'text-green-600',
          variant === 'warning' && 'text-yellow-600',
          variant === 'destructive' && 'text-red-600'
        )}
      >
        {percentage.toFixed(0)}%
      </p>
    </div>
  );
}

function IssueCard({ issue }: { issue: QATopIssue }) {
  return (
    <div className="border rounded-lg p-4">
      <div className="flex items-center justify-between mb-2">
        <div className="flex items-center gap-2">
          <Badge variant="outline">#{issue.rank}</Badge>
          <span className="font-medium capitalize">
            {issue.issue_type?.replace(/_/g, ' ')}
          </span>
        </div>
        <span className="text-sm text-muted-foreground">
          {issue.count} กรณี ({issue.percentage.toFixed(1)}%)
        </span>
      </div>
      <div className="space-y-2 text-sm">
        <p className="text-muted-foreground">
          <strong className="text-foreground">รูปแบบ:</strong> {issue.pattern}
        </p>
        <p className="text-muted-foreground">
          <strong className="text-foreground">สาเหตุ:</strong>{' '}
          {issue.root_cause}
        </p>
        {issue.prompt_section && (
          <p className="text-muted-foreground">
            <strong className="text-foreground">ส่วนที่ได้รับผลกระทบ:</strong>{' '}
            {issue.prompt_section}
          </p>
        )}
      </div>
      {issue.example_conversations && issue.example_conversations.length > 0 && (
        <div className="mt-3 pt-3 border-t">
          <p className="text-sm font-medium mb-2">ตัวอย่าง:</p>
          <div className="bg-muted/50 rounded p-2 text-sm">
            <p className="text-muted-foreground">
              <strong>ถาม:</strong> {issue.example_conversations[0].user_question}
            </p>
            <p className="text-muted-foreground mt-1">
              <strong>ตอบ:</strong> {issue.example_conversations[0].bot_response}
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

