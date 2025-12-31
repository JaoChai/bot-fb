import { useState, useEffect } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { EvaluationProgressStepper } from '@/components/evaluation/EvaluationProgressStepper';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { useToast } from '@/hooks/use-toast';
import { getErrorMessage } from '@/lib/api';
import {
  useEvaluation,
  useEvaluationTestCases,
  useEvaluationReport,
  useEvaluationProgress,
  useCancelEvaluation,
  useRetryEvaluation,
} from '@/hooks/useEvaluations';
import {
  ArrowLeft,
  Loader2,
  PlayCircle,
  CheckCircle2,
  XCircle,
  Clock,
  Target,
  TrendingUp,
  RotateCcw,
  StopCircle,
  DollarSign,
  MessageSquare,
  User,
  Bot,
  Lightbulb,
  AlertTriangle,
  ThumbsUp,
  FileText,
} from 'lucide-react';
import {
  Radar,
  RadarChart,
  PolarGrid,
  PolarAngleAxis,
  PolarRadiusAxis,
  ResponsiveContainer,
  Tooltip,
} from 'recharts';
import type { EvaluationTestCase, EvaluationReport } from '@/types/api';

// Status badge configuration
const STATUS_CONFIG: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline'; icon: React.ElementType }> = {
  pending: { label: 'รอดำเนินการ', variant: 'secondary', icon: Clock },
  generating_tests: { label: 'สร้าง Test Cases', variant: 'default', icon: Loader2 },
  running: { label: 'กำลังทดสอบ', variant: 'default', icon: PlayCircle },
  evaluating: { label: 'กำลังประเมิน', variant: 'default', icon: Target },
  generating_report: { label: 'สร้างรายงาน', variant: 'default', icon: TrendingUp },
  completed: { label: 'เสร็จสิ้น', variant: 'outline', icon: CheckCircle2 },
  failed: { label: 'ล้มเหลว', variant: 'destructive', icon: XCircle },
  cancelled: { label: 'ยกเลิก', variant: 'secondary', icon: StopCircle },
};

function StatusBadge({ status }: { status: string }) {
  const config = STATUS_CONFIG[status] || STATUS_CONFIG.pending;
  const Icon = config.icon;
  const isAnimated = ['generating_tests', 'running', 'evaluating', 'generating_report'].includes(status);

  return (
    <Badge variant={config.variant} className="gap-1">
      <Icon className={`h-3 w-3 ${isAnimated ? 'animate-spin' : ''}`} />
      {config.label}
    </Badge>
  );
}

function MetricRadarChart({ scores }: { scores: { answer_relevancy: number | null; faithfulness: number | null; role_adherence: number | null; context_precision: number | null; task_completion: number | null } }) {
  const data = [
    { metric: 'Relevancy', value: (scores.answer_relevancy ?? 0) * 100, fullMark: 100 },
    { metric: 'Faithfulness', value: (scores.faithfulness ?? 0) * 100, fullMark: 100 },
    { metric: 'Role', value: (scores.role_adherence ?? 0) * 100, fullMark: 100 },
    { metric: 'Context', value: (scores.context_precision ?? 0) * 100, fullMark: 100 },
    { metric: 'Task', value: (scores.task_completion ?? 0) * 100, fullMark: 100 },
  ];

  return (
    <ResponsiveContainer width="100%" height={300}>
      <RadarChart cx="50%" cy="50%" outerRadius="80%" data={data}>
        <PolarGrid />
        <PolarAngleAxis dataKey="metric" className="text-xs" />
        <PolarRadiusAxis angle={90} domain={[0, 100]} className="text-xs" />
        <Radar
          name="Score"
          dataKey="value"
          stroke="#2563eb"
          fill="#2563eb"
          fillOpacity={0.3}
        />
        <Tooltip
          formatter={(value) => [`${(value as number).toFixed(1)}%`, 'Score']}
        />
      </RadarChart>
    </ResponsiveContainer>
  );
}

function ScoreCard({ label, score, description }: { label: string; score: number | null; description: string }) {
  if (score === null) return null;

  const getScoreColor = (s: number) => {
    if (s >= 0.8) return 'text-green-600';
    if (s >= 0.6) return 'text-yellow-600';
    return 'text-red-600';
  };

  const getScoreBg = (s: number) => {
    if (s >= 0.8) return 'bg-green-50 border-green-200';
    if (s >= 0.6) return 'bg-yellow-50 border-yellow-200';
    return 'bg-red-50 border-red-200';
  };

  return (
    <div className={`p-4 rounded-lg border ${getScoreBg(score)}`}>
      <div className={`text-2xl font-bold ${getScoreColor(score)}`}>
        {(score * 100).toFixed(0)}%
      </div>
      <div className="font-medium text-sm">{label}</div>
      <div className="text-xs text-muted-foreground mt-1">{description}</div>
    </div>
  );
}

function TestCaseCard({ testCase, onClick }: { testCase: EvaluationTestCase; onClick: () => void }) {
  const getStatusIcon = () => {
    switch (testCase.status) {
      case 'completed':
        return <CheckCircle2 className="h-4 w-4 text-green-600" />;
      case 'failed':
        return <XCircle className="h-4 w-4 text-red-600" />;
      case 'running':
        return <Loader2 className="h-4 w-4 text-blue-600 animate-spin" />;
      default:
        return <Clock className="h-4 w-4 text-gray-400" />;
    }
  };

  const overallScore = testCase.scores.overall;

  return (
    <Card
      className="cursor-pointer hover:shadow-md transition-shadow"
      onClick={onClick}
    >
      <CardContent className="p-4">
        <div className="flex items-start justify-between gap-4">
          <div className="flex-1 min-w-0">
            <div className="flex items-center gap-2 mb-1">
              {getStatusIcon()}
              <span className="font-medium truncate">{testCase.title}</span>
            </div>
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Badge variant="outline" className="text-xs">{testCase.persona_key}</Badge>
              <Badge variant="secondary" className="text-xs">{testCase.test_type}</Badge>
            </div>
          </div>
          {overallScore !== null && (
            <div className={`text-lg font-bold ${
              overallScore >= 0.8 ? 'text-green-600' :
              overallScore >= 0.6 ? 'text-yellow-600' : 'text-red-600'
            }`}>
              {(overallScore * 100).toFixed(0)}%
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

function TestCaseDetailDialog({
  testCase,
  open,
  onOpenChange,
}: {
  testCase: EvaluationTestCase | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  if (!testCase) return null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[80vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <MessageSquare className="h-5 w-5" />
            {testCase.title}
          </DialogTitle>
          <div className="flex items-center gap-2 pt-2">
            <Badge variant="outline">{testCase.persona_key}</Badge>
            <Badge variant="secondary">{testCase.test_type}</Badge>
            {testCase.scores.overall !== null && (
              <Badge variant={testCase.scores.overall >= 0.7 ? 'default' : 'destructive'}>
                {(testCase.scores.overall * 100).toFixed(0)}% overall
              </Badge>
            )}
          </div>
        </DialogHeader>

        <ScrollArea className="flex-1 pr-4">
          <div className="space-y-4">
            {/* Scores */}
            {testCase.scores.overall !== null && (
              <div className="grid grid-cols-5 gap-2">
                {Object.entries(testCase.scores).map(([key, value]) => {
                  if (key === 'overall' || value === null) return null;
                  const labels: Record<string, string> = {
                    answer_relevancy: 'Relevancy',
                    faithfulness: 'Faithful',
                    role_adherence: 'Role',
                    context_precision: 'Context',
                    task_completion: 'Task',
                  };
                  return (
                    <div key={key} className="text-center p-2 bg-muted rounded">
                      <div className={`text-sm font-bold ${
                        value >= 0.8 ? 'text-green-600' :
                        value >= 0.6 ? 'text-yellow-600' : 'text-red-600'
                      }`}>
                        {(value * 100).toFixed(0)}%
                      </div>
                      <div className="text-xs text-muted-foreground">{labels[key] || key}</div>
                    </div>
                  );
                })}
              </div>
            )}

            {/* Messages */}
            <div className="space-y-3">
              <h4 className="font-medium">บทสนทนา</h4>
              {testCase.messages?.map((msg, idx) => (
                <div
                  key={idx}
                  className={`flex gap-3 ${msg.role === 'assistant' ? 'flex-row-reverse' : ''}`}
                >
                  <div className={`w-8 h-8 rounded-full flex items-center justify-center ${
                    msg.role === 'user' ? 'bg-blue-100' : 'bg-green-100'
                  }`}>
                    {msg.role === 'user' ? (
                      <User className="h-4 w-4 text-blue-600" />
                    ) : (
                      <Bot className="h-4 w-4 text-green-600" />
                    )}
                  </div>
                  <div className={`flex-1 max-w-[80%] p-3 rounded-lg ${
                    msg.role === 'user'
                      ? 'bg-blue-50 text-left'
                      : 'bg-green-50 text-left'
                  }`}>
                    <div className="text-sm whitespace-pre-wrap">{msg.content}</div>
                    {msg.turn_scores && (
                      <div className="mt-2 pt-2 border-t text-xs text-muted-foreground">
                        Turn scores: {JSON.stringify(msg.turn_scores)}
                      </div>
                    )}
                  </div>
                </div>
              ))}
            </div>

            {/* Detailed Feedback */}
            {testCase.detailed_feedback && (
              <div className="space-y-2">
                <h4 className="font-medium">Feedback</h4>
                <ScrollArea className="max-h-[200px]">
                  <div className="text-sm bg-muted p-3 rounded-lg whitespace-pre-wrap">
                    {typeof testCase.detailed_feedback === 'string'
                      ? testCase.detailed_feedback
                      : JSON.stringify(testCase.detailed_feedback, null, 2)}
                  </div>
                </ScrollArea>
              </div>
            )}
          </div>
        </ScrollArea>
      </DialogContent>
    </Dialog>
  );
}

function ReportSection({ report }: { report: EvaluationReport }) {
  const getPriorityColor = (priority: string) => {
    switch (priority) {
      case 'high': return 'bg-red-100 text-red-700';
      case 'medium': return 'bg-yellow-100 text-yellow-700';
      default: return 'bg-gray-100 text-gray-700';
    }
  };

  const getPriorityLabel = (priority: string) => {
    switch (priority) {
      case 'high': return 'สำคัญมาก';
      case 'medium': return 'ปานกลาง';
      default: return 'ทั่วไป';
    }
  };

  return (
    <div className="space-y-6">
      {/* Executive Summary */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <FileText className="h-5 w-5" />
            สรุปภาพรวม
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="text-sm leading-relaxed whitespace-pre-wrap">{report.executive_summary}</div>
        </CardContent>
      </Card>

      <div className="grid md:grid-cols-2 gap-4">
        {/* Strengths */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-base text-green-600">
              <ThumbsUp className="h-5 w-5" />
              จุดแข็ง ({report.strengths.length})
            </CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="space-y-3">
              {report.strengths.map((item, idx) => (
                <li key={idx} className="flex items-start gap-2">
                  <CheckCircle2 className="h-4 w-4 text-green-600 mt-0.5 shrink-0" />
                  <div>
                    <div className="text-sm font-medium flex items-center gap-2">
                      {item.label}
                      <span className="text-green-600 text-xs">({(item.score * 100).toFixed(0)}%)</span>
                    </div>
                    <div className="text-xs text-muted-foreground">{item.description}</div>
                  </div>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        {/* Weaknesses */}
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="flex items-center gap-2 text-base text-red-600">
              <AlertTriangle className="h-5 w-5" />
              จุดที่ต้องปรับปรุง ({report.weaknesses.length})
            </CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="space-y-3">
              {report.weaknesses.map((item, idx) => (
                <li key={idx} className="flex items-start gap-2">
                  <XCircle className="h-4 w-4 text-red-600 mt-0.5 shrink-0" />
                  <div>
                    <div className="text-sm font-medium flex items-center gap-2">
                      {item.label}
                      <span className="text-red-600 text-xs">({(item.score * 100).toFixed(0)}%)</span>
                    </div>
                    <div className="text-xs text-muted-foreground">{item.description}</div>
                  </div>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      </div>

      {/* Recommendations */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="flex items-center gap-2 text-base text-blue-600">
            <Lightbulb className="h-5 w-5" />
            คำแนะนำ ({report.recommendations.length})
          </CardTitle>
        </CardHeader>
        <CardContent>
          <ul className="space-y-4">
            {report.recommendations.map((item, idx) => (
              <li key={idx} className="border-l-2 border-blue-200 pl-3">
                <div className="flex items-center gap-2 mb-1">
                  <span className="w-5 h-5 rounded-full bg-blue-100 text-blue-600 text-xs flex items-center justify-center shrink-0">
                    {idx + 1}
                  </span>
                  <span className="text-sm font-medium">{item.title}</span>
                  <span className={`text-xs px-2 py-0.5 rounded ${getPriorityColor(item.priority)}`}>
                    {getPriorityLabel(item.priority)}
                  </span>
                </div>
                <p className="text-xs text-muted-foreground ml-7">{item.description}</p>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      {/* Prompt Suggestions */}
      {report.prompt_suggestions.length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">แนะนำปรับ Prompt</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="space-y-4">
              {report.prompt_suggestions.map((item, idx) => (
                <li key={idx} className="space-y-2">
                  <div className="text-sm font-medium">{item.suggestion}</div>
                  <pre className="text-xs bg-muted p-3 rounded-lg overflow-x-auto whitespace-pre-wrap">
                    {item.example}
                  </pre>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      {/* KB Gaps */}
      {report.kb_gaps.length > 0 && (
        <Card>
          <CardHeader className="pb-2">
            <CardTitle className="text-base">ช่องว่าง Knowledge Base</CardTitle>
            <CardDescription>หัวข้อที่ควรเพิ่มข้อมูลใน KB</CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="space-y-2">
              {report.kb_gaps.map((item, idx) => (
                <li key={idx} className="text-sm flex items-start gap-2 p-2 bg-orange-50 rounded">
                  <AlertTriangle className="h-4 w-4 text-orange-600 mt-0.5 shrink-0" />
                  <div>
                    {item.topics.map((topic, tidx) => (
                      <span key={tidx} className="inline-block bg-orange-100 text-orange-700 px-2 py-0.5 rounded text-xs mr-1 mb-1">
                        {topic}
                      </span>
                    ))}
                  </div>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}
    </div>
  );
}

export function EvaluationDetailPage() {
  const navigate = useNavigate();
  const { evaluationId } = useParams<{ evaluationId: string }>();
  const [searchParams] = useSearchParams();
  const botId = Number(searchParams.get('bot')) || null;
  const evalId = Number(evaluationId) || null;
  const { toast } = useToast();

  const [selectedTestCase, setSelectedTestCase] = useState<EvaluationTestCase | null>(null);
  const queryClient = useQueryClient();

  // Fetch evaluation
  const { data: evaluation, isLoading } = useEvaluation(botId, evalId);

  // Fetch progress for running evaluations
  const isRunning = evaluation && ['pending', 'generating_tests', 'running', 'evaluating', 'generating_report'].includes(evaluation.status);
  const { data: progress } = useEvaluationProgress(botId, evalId, isRunning);

  // Fetch test cases
  const { data: testCasesData } = useEvaluationTestCases(botId, evalId);
  const testCases = testCasesData?.data ?? [];

  // Fetch report for completed evaluations
  const { data: report } = useEvaluationReport(botId, evalId);

  // Mutations
  const cancelMutation = useCancelEvaluation(botId);
  const retryMutation = useRetryEvaluation(botId);

  // Auto-refresh for running evaluations
  useEffect(() => {
    if (!isRunning || !botId || !evalId) return;

    const interval = setInterval(() => {
      queryClient.invalidateQueries({ queryKey: ['evaluation', botId, evalId] });
      queryClient.invalidateQueries({ queryKey: ['evaluation-progress', botId, evalId] });
    }, 3000);

    return () => clearInterval(interval);
  }, [isRunning, botId, evalId, queryClient]);

  const handleCancel = async () => {
    if (!evalId) return;
    try {
      await cancelMutation.mutateAsync(evalId);
      toast({ title: 'ยกเลิกการประเมินแล้ว' });
    } catch (error) {
      toast({ title: 'เกิดข้อผิดพลาด', description: getErrorMessage(error), variant: 'destructive' });
    }
  };

  const handleRetry = async () => {
    if (!evalId) return;
    try {
      await retryMutation.mutateAsync(evalId);
      toast({ title: 'เริ่มการประเมินใหม่แล้ว' });
    } catch (error) {
      toast({ title: 'เกิดข้อผิดพลาด', description: getErrorMessage(error), variant: 'destructive' });
    }
  };

  // Loading state
  if (isLoading) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (!evaluation) {
    return (
      <div className="min-h-screen bg-background">
        <div className="max-w-4xl mx-auto px-4 py-8">
          <div className="text-center py-12">
            <h2 className="text-lg font-medium">ไม่พบการประเมิน</h2>
            <Button className="mt-4" onClick={() => navigate(-1)}>
              กลับ
            </Button>
          </div>
        </div>
      </div>
    );
  }

  const currentProgress = progress || evaluation.progress;

  return (
    <div className="min-h-screen bg-background">
      <div className="max-w-4xl mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex items-center gap-4 mb-8">
          <Button variant="ghost" size="icon" onClick={() => navigate(`/evaluations?bot=${botId}`)}>
            <ArrowLeft className="h-5 w-5" />
          </Button>
          <div className="flex-1">
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-bold tracking-tight">{evaluation.name}</h1>
              <StatusBadge status={evaluation.status} />
            </div>
            <p className="text-muted-foreground mt-1">
              Flow: {evaluation.flow?.name} | {new Date(evaluation.created_at).toLocaleString('th-TH')}
            </p>
          </div>
          {/* Actions */}
          <div className="flex gap-2">
            {isRunning && (
              <Button variant="outline" onClick={handleCancel} disabled={cancelMutation.isPending}>
                {cancelMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <StopCircle className="h-4 w-4" />}
                <span className="ml-2">ยกเลิก</span>
              </Button>
            )}
            {evaluation.status === 'failed' && (
              <Button onClick={handleRetry} disabled={retryMutation.isPending}>
                {retryMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : <RotateCcw className="h-4 w-4" />}
                <span className="ml-2">ลองใหม่</span>
              </Button>
            )}
          </div>
        </div>

        {/* Progress for running evaluations */}
        {isRunning && (
          <Card className="mb-6">
            <CardContent className="py-6">
              <EvaluationProgressStepper
                status={evaluation.status}
                completedTestCases={currentProgress.completed_test_cases}
                totalTestCases={currentProgress.total_test_cases}
                percent={currentProgress.percent}
              />
            </CardContent>
          </Card>
        )}

        {/* Error message for failed evaluations */}
        {evaluation.status === 'failed' && evaluation.error_message && (
          <Card className="mb-6 border-destructive">
            <CardContent className="py-4">
              <div className="flex items-start gap-3 text-destructive">
                <XCircle className="h-5 w-5 mt-0.5" />
                <div>
                  <div className="font-medium">การประเมินล้มเหลว</div>
                  <div className="text-sm mt-1">{evaluation.error_message}</div>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Completed evaluation content */}
        {evaluation.status === 'completed' && (
          <Tabs defaultValue="overview" className="space-y-6">
            <TabsList>
              <TabsTrigger value="overview">ภาพรวม</TabsTrigger>
              <TabsTrigger value="test-cases">Test Cases ({testCases.length})</TabsTrigger>
              <TabsTrigger value="report">รายงาน</TabsTrigger>
            </TabsList>

            {/* Overview Tab */}
            <TabsContent value="overview" className="space-y-6">
              {/* Overall Score */}
              <Card>
                <CardHeader className="text-center pb-2">
                  <CardTitle className="text-lg">คะแนนรวม</CardTitle>
                </CardHeader>
                <CardContent className="text-center">
                  <div className={`text-5xl font-bold ${
                    (evaluation.overall_score ?? 0) >= 0.8 ? 'text-green-600' :
                    (evaluation.overall_score ?? 0) >= 0.6 ? 'text-yellow-600' : 'text-red-600'
                  }`}>
                    {((evaluation.overall_score ?? 0) * 100).toFixed(0)}%
                  </div>
                </CardContent>
              </Card>

              {/* Radar Chart */}
              {evaluation.metric_scores && (
                <Card>
                  <CardHeader>
                    <CardTitle className="text-lg">Metrics Overview</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <MetricRadarChart scores={evaluation.metric_scores} />
                  </CardContent>
                </Card>
              )}

              {/* Individual Metrics */}
              {evaluation.metric_scores && (
                <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                  <ScoreCard
                    label="Answer Relevancy"
                    score={evaluation.metric_scores.answer_relevancy}
                    description="ตอบตรงคำถาม"
                  />
                  <ScoreCard
                    label="Faithfulness"
                    score={evaluation.metric_scores.faithfulness}
                    description="ไม่ hallucinate"
                  />
                  <ScoreCard
                    label="Role Adherence"
                    score={evaluation.metric_scores.role_adherence}
                    description="ทำตาม persona"
                  />
                  <ScoreCard
                    label="Context Precision"
                    score={evaluation.metric_scores.context_precision}
                    description="ดึง KB ถูกต้อง"
                  />
                  <ScoreCard
                    label="Task Completion"
                    score={evaluation.metric_scores.task_completion}
                    description="ช่วยลูกค้าสำเร็จ"
                  />
                </div>
              )}

              {/* Stats */}
              <Card>
                <CardContent className="py-4">
                  <div className="grid grid-cols-3 gap-4 text-center">
                    <div>
                      <div className="text-2xl font-bold">{evaluation.progress.total_test_cases}</div>
                      <div className="text-sm text-muted-foreground">Test Cases</div>
                    </div>
                    <div>
                      <div className="text-2xl font-bold flex items-center justify-center gap-1">
                        <DollarSign className="h-5 w-5" />
                        {evaluation.estimated_cost.toFixed(2)}
                      </div>
                      <div className="text-sm text-muted-foreground">ค่าใช้จ่าย</div>
                    </div>
                    <div>
                      <div className="text-2xl font-bold">{evaluation.total_tokens_used.toLocaleString()}</div>
                      <div className="text-sm text-muted-foreground">Tokens</div>
                    </div>
                  </div>
                </CardContent>
              </Card>
            </TabsContent>

            {/* Test Cases Tab */}
            <TabsContent value="test-cases">
              <div className="grid gap-4">
                {testCases.map((tc) => (
                  <TestCaseCard
                    key={tc.id}
                    testCase={tc}
                    onClick={() => setSelectedTestCase(tc)}
                  />
                ))}
              </div>
            </TabsContent>

            {/* Report Tab */}
            <TabsContent value="report">
              {report ? (
                <ReportSection report={report} />
              ) : (
                <Card>
                  <CardContent className="py-12 text-center">
                    <Loader2 className="h-8 w-8 animate-spin mx-auto text-muted-foreground mb-4" />
                    <p className="text-muted-foreground">กำลังโหลดรายงาน...</p>
                  </CardContent>
                </Card>
              )}
            </TabsContent>
          </Tabs>
        )}
      </div>

      {/* Test Case Detail Dialog */}
      <TestCaseDetailDialog
        testCase={selectedTestCase}
        open={!!selectedTestCase}
        onOpenChange={(open) => !open && setSelectedTestCase(null)}
      />
    </div>
  );
}

export default EvaluationDetailPage;
