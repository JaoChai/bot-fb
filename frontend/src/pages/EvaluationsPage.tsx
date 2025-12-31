import { useState, useMemo, useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ScrollArea } from '@/components/ui/scroll-area';
import { useToast } from '@/hooks/use-toast';
import { getErrorMessage } from '@/lib/api';
import { useBots } from '@/hooks/useKnowledgeBase';
import { useFlows } from '@/hooks/useFlows';
import { useEvaluationOperations, useEvaluationPersonas } from '@/hooks/useEvaluations';
import {
  ArrowLeft,
  Plus,
  Loader2,
  PlayCircle,
  CheckCircle2,
  XCircle,
  Clock,
  AlertCircle,
  Target,
  TrendingUp,
  Trash2,
  RotateCcw,
  StopCircle,
  DollarSign,
  ChevronRight,
  ChevronDown,
  Settings2,
  User,
  Users,
  ShieldAlert,
  MessageSquare,
  Check,
} from 'lucide-react';
import type { Evaluation, CreateEvaluationData } from '@/types/api';
import { EvaluationProgressStepper } from '@/components/evaluation/EvaluationProgressStepper';

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

function ScoreDisplay({ score, label }: { score: number | null; label: string }) {
  if (score === null) return null;

  const getScoreColor = (s: number) => {
    if (s >= 0.8) return 'text-green-600';
    if (s >= 0.6) return 'text-yellow-600';
    return 'text-red-600';
  };

  return (
    <div className="text-center">
      <div className={`text-2xl font-bold ${getScoreColor(score)}`}>
        {(score * 100).toFixed(0)}%
      </div>
      <div className="text-xs text-muted-foreground">{label}</div>
    </div>
  );
}

function EvaluationCard({
  evaluation,
  onView,
  onCancel,
  onRetry,
  onDelete,
  isDeleting,
  isCancelling,
}: {
  evaluation: Evaluation;
  onView: () => void;
  onCancel: () => void;
  onRetry: () => void;
  onDelete: () => void;
  isDeleting: boolean;
  isCancelling: boolean;
}) {
  const isRunning = ['pending', 'generating_tests', 'running', 'evaluating', 'generating_report'].includes(evaluation.status);
  const isFailed = evaluation.status === 'failed';
  const isCompleted = evaluation.status === 'completed';

  return (
    <Card className="cursor-pointer hover:shadow-md transition-shadow" onClick={onView}>
      <CardHeader className="pb-2">
        <div className="flex items-start justify-between">
          <div className="space-y-1">
            <CardTitle className="text-base font-medium">{evaluation.name}</CardTitle>
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <span>Flow: {evaluation.flow?.name || 'Unknown'}</span>
              <span>|</span>
              <span>{new Date(evaluation.created_at).toLocaleDateString('th-TH')}</span>
            </div>
          </div>
          <StatusBadge status={evaluation.status} />
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Progress stepper for running evaluations */}
        {isRunning && (
          <EvaluationProgressStepper
            status={evaluation.status}
            completedTestCases={evaluation.progress.completed_test_cases}
            totalTestCases={evaluation.progress.total_test_cases}
            percent={evaluation.progress.percent}
          />
        )}

        {/* Scores for completed evaluations */}
        {isCompleted && evaluation.overall_score !== null && (
          <div className="grid grid-cols-3 gap-4">
            <ScoreDisplay score={evaluation.overall_score} label="Overall" />
            <ScoreDisplay score={evaluation.metric_scores?.faithfulness ?? null} label="Faithfulness" />
            <ScoreDisplay score={evaluation.metric_scores?.answer_relevancy ?? null} label="Relevancy" />
          </div>
        )}

        {/* Error message for failed evaluations */}
        {isFailed && evaluation.error_message && (
          <div className="text-sm text-destructive bg-destructive/10 p-2 rounded">
            {evaluation.error_message}
          </div>
        )}

        {/* Cost info */}
        {(isCompleted || isFailed) && (
          <div className="flex items-center justify-between text-sm text-muted-foreground">
            <div className="flex items-center gap-1">
              <DollarSign className="h-3 w-3" />
              <span>${evaluation.estimated_cost.toFixed(2)}</span>
            </div>
            <div className="flex items-center gap-1">
              <Target className="h-3 w-3" />
              <span>{evaluation.progress.total_test_cases} test cases</span>
            </div>
          </div>
        )}

        {/* Actions */}
        <div className="flex items-center justify-end gap-2 pt-2 border-t" onClick={(e) => e.stopPropagation()}>
          {isRunning && (
            <Button
              variant="outline"
              size="sm"
              onClick={onCancel}
              disabled={isCancelling}
            >
              {isCancelling ? <Loader2 className="h-3 w-3 animate-spin" /> : <StopCircle className="h-3 w-3" />}
              <span className="ml-1">ยกเลิก</span>
            </Button>
          )}
          {isFailed && (
            <Button
              variant="outline"
              size="sm"
              onClick={onRetry}
            >
              <RotateCcw className="h-3 w-3" />
              <span className="ml-1">ลองใหม่</span>
            </Button>
          )}
          {!isRunning && (
            <Button
              variant="ghost"
              size="sm"
              onClick={onDelete}
              disabled={isDeleting}
              className="text-destructive hover:text-destructive"
            >
              {isDeleting ? <Loader2 className="h-3 w-3 animate-spin" /> : <Trash2 className="h-3 w-3" />}
            </Button>
          )}
          <Button variant="ghost" size="sm" onClick={onView}>
            <span>ดูรายละเอียด</span>
            <ChevronRight className="h-3 w-3 ml-1" />
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

// Persona icon mapping
const PERSONA_ICONS: Record<string, React.ElementType> = {
  new_customer: User,
  regular_customer: Users,
  edge_case: ShieldAlert,
  complaint: MessageSquare,
};

function CreateEvaluationDialog({
  open,
  onOpenChange,
  botId,
  onSuccess,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  botId: number;
  onSuccess: () => void;
}) {
  const { toast } = useToast();
  const { data: flowsData, isLoading: isFlowsLoading } = useFlows(botId);
  const { data: personasData, isLoading: isPersonasLoading } = useEvaluationPersonas();
  const { createEvaluation, isCreating } = useEvaluationOperations(botId);

  const flows = flowsData?.data ?? [];
  const personas = personasData ?? [];

  const [formData, setFormData] = useState<CreateEvaluationData>({
    flow_id: 0,
    name: '',
    test_count: 40,
    personas: [],
    generator_model: 'anthropic/claude-3-haiku-20240307',
    simulator_model: 'anthropic/claude-3-haiku-20240307',
    judge_model: 'anthropic/claude-3.5-sonnet',
    include_multi_turn: true,
    include_edge_cases: true,
  });

  const [isModelSettingsOpen, setIsModelSettingsOpen] = useState(false);

  const handleSubmit = async () => {
    if (!formData.flow_id) {
      toast({ title: 'กรุณาเลือก Flow', variant: 'destructive' });
      return;
    }

    try {
      await createEvaluation?.({
        ...formData,
        personas: formData.personas?.length ? formData.personas : undefined,
      });
      toast({ title: 'เริ่มการประเมินแล้ว', description: 'ระบบกำลังสร้าง test cases' });
      onSuccess();
      onOpenChange(false);
      // Reset form
      setFormData({
        flow_id: 0,
        name: '',
        test_count: 40,
        personas: [],
        generator_model: 'anthropic/claude-3-haiku-20240307',
        simulator_model: 'anthropic/claude-3-haiku-20240307',
        judge_model: 'anthropic/claude-3.5-sonnet',
        include_multi_turn: true,
        include_edge_cases: true,
      });
    } catch (error) {
      toast({ title: 'เกิดข้อผิดพลาด', description: getErrorMessage(error), variant: 'destructive' });
    }
  };

  const togglePersona = (key: string) => {
    setFormData((prev) => ({
      ...prev,
      personas: prev.personas?.includes(key)
        ? prev.personas.filter((p) => p !== key)
        : [...(prev.personas || []), key],
    }));
  };

  // Estimate cost based on test count
  const estimatedCost = useMemo(() => {
    const testCount = formData.test_count || 40;
    // Rough estimate: $0.07 per test case
    return (testCount * 0.07).toFixed(2);
  }, [formData.test_count]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl max-h-[90vh] flex flex-col">
        <DialogHeader>
          <DialogTitle>สร้างการประเมินใหม่</DialogTitle>
          <DialogDescription>
            ระบบจะสร้าง test cases จาก Knowledge Base และประเมินคุณภาพการตอบของ Bot
          </DialogDescription>
        </DialogHeader>

        <ScrollArea className="flex-1 pr-4">
          <div className="space-y-6 py-4">
            {/* Section 1: Basic Settings */}
            <div className="space-y-4">
              {/* Flow Selection */}
              <div className="space-y-2">
                <Label className="text-sm font-medium">เลือก Flow *</Label>
                <Select
                  value={formData.flow_id ? String(formData.flow_id) : ''}
                  onValueChange={(value) => setFormData((prev) => ({ ...prev, flow_id: Number(value) }))}
                  disabled={isFlowsLoading}
                >
                  <SelectTrigger>
                    <SelectValue placeholder={isFlowsLoading ? 'กำลังโหลด...' : 'เลือก Flow ที่ต้องการทดสอบ'} />
                  </SelectTrigger>
                  <SelectContent>
                    {flows.map((flow) => (
                      <SelectItem key={flow.id} value={String(flow.id)}>
                        {flow.name} {flow.is_default && '(Default)'}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Name (optional) */}
              <div className="space-y-2">
                <Label className="text-sm font-medium">ชื่อการประเมิน <span className="text-muted-foreground font-normal">(ไม่บังคับ)</span></Label>
                <Input
                  placeholder="เช่น ทดสอบ prompt v2"
                  value={formData.name}
                  onChange={(e) => setFormData((prev) => ({ ...prev, name: e.target.value }))}
                />
              </div>

              {/* Test Count */}
              <div className="space-y-3">
                <div className="flex justify-between items-center">
                  <Label className="text-sm font-medium">จำนวน Test Cases</Label>
                  <Badge variant="secondary" className="font-mono">{formData.test_count}</Badge>
                </div>
                <Slider
                  value={[formData.test_count || 40]}
                  onValueChange={([value]) => setFormData((prev) => ({ ...prev, test_count: value }))}
                  min={10}
                  max={100}
                  step={5}
                  className="py-2"
                />
                <div className="flex justify-between text-xs text-muted-foreground">
                  <span>10 (เร็ว)</span>
                  <span>100 (ละเอียด)</span>
                </div>
              </div>
            </div>

            {/* Section 2: Personas */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label className="text-sm font-medium">Personas</Label>
                <span className="text-xs text-muted-foreground">
                  {formData.personas?.length ? `เลือก ${formData.personas.length} personas` : 'ใช้ทั้งหมด'}
                </span>
              </div>
              {isPersonasLoading ? (
                <div className="flex items-center justify-center py-4">
                  <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                </div>
              ) : (
                <div className="grid grid-cols-2 gap-3">
                  {personas.map((persona) => {
                    const isSelected = formData.personas?.includes(persona.key);
                    const Icon = PERSONA_ICONS[persona.key] || User;
                    return (
                      <button
                        key={persona.key}
                        type="button"
                        onClick={() => togglePersona(persona.key)}
                        className={`
                          relative p-3 rounded-lg border-2 text-left transition-all cursor-pointer
                          ${isSelected
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:border-muted-foreground/50 hover:bg-muted/50'
                          }
                        `}
                      >
                        {isSelected && (
                          <div className="absolute top-2 right-2">
                            <Check className="h-4 w-4 text-primary" />
                          </div>
                        )}
                        <div className="flex items-start gap-3">
                          <div className={`
                            p-2 rounded-lg
                            ${isSelected ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'}
                          `}>
                            <Icon className="h-4 w-4" />
                          </div>
                          <div className="flex-1 min-w-0">
                            <div className="font-medium text-sm">{persona.name}</div>
                            <div className="text-xs text-muted-foreground line-clamp-2 mt-0.5">
                              {persona.description}
                            </div>
                          </div>
                        </div>
                      </button>
                    );
                  })}
                </div>
              )}
            </div>

            {/* Section 3: Test Options */}
            <div className="space-y-3 p-4 bg-muted/50 rounded-lg">
              <Label className="text-sm font-medium">ตัวเลือกการทดสอบ</Label>
              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <div>
                    <Label htmlFor="multi-turn" className="text-sm cursor-pointer">Multi-turn conversations</Label>
                    <p className="text-xs text-muted-foreground">ทดสอบบทสนทนาหลายรอบ</p>
                  </div>
                  <Switch
                    id="multi-turn"
                    checked={formData.include_multi_turn}
                    onCheckedChange={(checked) => setFormData((prev) => ({ ...prev, include_multi_turn: checked }))}
                  />
                </div>
                <div className="flex items-center justify-between">
                  <div>
                    <Label htmlFor="edge-cases" className="text-sm cursor-pointer">Edge cases</Label>
                    <p className="text-xs text-muted-foreground">รวมกรณีพิเศษและขอบเขต</p>
                  </div>
                  <Switch
                    id="edge-cases"
                    checked={formData.include_edge_cases}
                    onCheckedChange={(checked) => setFormData((prev) => ({ ...prev, include_edge_cases: checked }))}
                  />
                </div>
              </div>
            </div>

            {/* Section 4: Advanced Model Settings (Collapsible) */}
            <Collapsible open={isModelSettingsOpen} onOpenChange={setIsModelSettingsOpen}>
              <CollapsibleTrigger asChild>
                <button
                  type="button"
                  className="flex items-center justify-between w-full p-3 rounded-lg border hover:bg-muted/50 transition-colors cursor-pointer"
                >
                  <div className="flex items-center gap-2">
                    <Settings2 className="h-4 w-4 text-muted-foreground" />
                    <span className="text-sm font-medium">Model Settings</span>
                  </div>
                  <ChevronDown className={`h-4 w-4 text-muted-foreground transition-transform ${isModelSettingsOpen ? 'rotate-180' : ''}`} />
                </button>
              </CollapsibleTrigger>
              <CollapsibleContent>
                <div className="space-y-3 pt-3 pl-1">
                  <div className="space-y-2">
                    <Label className="text-xs text-muted-foreground">Generator (สร้างคำถาม)</Label>
                    <Input
                      placeholder="anthropic/claude-3-haiku-20240307"
                      value={formData.generator_model}
                      onChange={(e) => setFormData((prev) => ({ ...prev, generator_model: e.target.value }))}
                      className="h-9 text-sm"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label className="text-xs text-muted-foreground">Simulator (จำลองลูกค้า)</Label>
                    <Input
                      placeholder="anthropic/claude-3-haiku-20240307"
                      value={formData.simulator_model}
                      onChange={(e) => setFormData((prev) => ({ ...prev, simulator_model: e.target.value }))}
                      className="h-9 text-sm"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label className="text-xs text-muted-foreground">Judge (ประเมินผล)</Label>
                    <Input
                      placeholder="anthropic/claude-3.5-sonnet"
                      value={formData.judge_model}
                      onChange={(e) => setFormData((prev) => ({ ...prev, judge_model: e.target.value }))}
                      className="h-9 text-sm"
                    />
                  </div>
                </div>
              </CollapsibleContent>
            </Collapsible>

            {/* Cost Summary */}
            <div className="flex items-center justify-between p-4 bg-primary/5 border border-primary/20 rounded-lg">
              <div>
                <div className="text-sm font-medium">ค่าใช้จ่ายโดยประมาณ</div>
                <div className="text-xs text-muted-foreground">ผ่าน OpenRouter API</div>
              </div>
              <div className="text-right">
                <div className="text-2xl font-bold text-primary">~${estimatedCost}</div>
              </div>
            </div>
          </div>
        </ScrollArea>

        <DialogFooter className="border-t pt-4">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            ยกเลิก
          </Button>
          <Button onClick={handleSubmit} disabled={isCreating || !formData.flow_id}>
            {isCreating ? (
              <>
                <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                กำลังเริ่ม...
              </>
            ) : (
              <>
                <PlayCircle className="h-4 w-4 mr-2" />
                เริ่มการประเมิน
              </>
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

export function EvaluationsPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const botId = Number(searchParams.get('bot')) || null;
  const { toast } = useToast();

  const [isCreateOpen, setIsCreateOpen] = useState(false);

  // Fetch bots for selector
  const { data: botsData, isLoading: isBotsLoading } = useBots();
  const bots = botsData?.data ?? [];

  // If no bot selected, use first bot
  const selectedBotId = botId || bots[0]?.id || null;

  const {
    evaluations,
    isLoading,
    isDeleting,
    isCancelling,
    cancelEvaluation,
    deleteEvaluation,
    refetch,
  } = useEvaluationOperations(selectedBotId);

  // Check if any evaluation is running (for auto-refresh)
  const hasRunningEvaluations = useMemo(() => {
    return evaluations.some(e =>
      ['pending', 'generating_tests', 'running', 'evaluating', 'generating_report'].includes(e.status)
    );
  }, [evaluations]);

  // Auto-refresh when evaluations are running
  useEffect(() => {
    if (!hasRunningEvaluations) return;

    const interval = setInterval(() => {
      refetch();
    }, 5000); // Every 5 seconds

    return () => clearInterval(interval);
  }, [hasRunningEvaluations, refetch]);

  const handleSelectBot = (id: string) => {
    navigate(`/evaluations?bot=${id}`);
  };

  const handleCancel = async (evaluationId: number) => {
    try {
      await cancelEvaluation?.(evaluationId);
      toast({ title: 'ยกเลิกการประเมินแล้ว' });
    } catch (error) {
      toast({ title: 'เกิดข้อผิดพลาด', description: getErrorMessage(error), variant: 'destructive' });
    }
  };

  const handleDelete = async (evaluationId: number) => {
    try {
      await deleteEvaluation?.(evaluationId);
      toast({ title: 'ลบการประเมินแล้ว' });
    } catch (error) {
      toast({ title: 'เกิดข้อผิดพลาด', description: getErrorMessage(error), variant: 'destructive' });
    }
  };

  // Loading state
  if (isBotsLoading) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  // No bots
  if (bots.length === 0) {
    return (
      <div className="min-h-screen bg-background">
        <div className="max-w-4xl mx-auto px-4 py-8">
          <div className="flex items-center gap-4 mb-8">
            <Button variant="ghost" size="icon" onClick={() => navigate('/bots')}>
              <ArrowLeft className="h-5 w-5" />
            </Button>
            <h1 className="text-2xl font-bold">AI Evaluation</h1>
          </div>
          <Card>
            <CardContent className="py-12 text-center">
              <AlertCircle className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
              <h3 className="text-lg font-medium mb-2">ยังไม่มีการเชื่อมต่อ</h3>
              <p className="text-muted-foreground mb-4">สร้างการเชื่อมต่อก่อนเริ่มการประเมิน</p>
              <Button onClick={() => navigate('/connections/add')}>
                <Plus className="h-4 w-4 mr-2" />
                สร้างการเชื่อมต่อ
              </Button>
            </CardContent>
          </Card>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background">
      <div className="max-w-4xl mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex items-center gap-4 mb-8">
          <Button variant="ghost" size="icon" onClick={() => navigate('/bots')}>
            <ArrowLeft className="h-5 w-5" />
          </Button>
          <div className="flex-1">
            <h1 className="text-2xl font-bold tracking-tight">AI Evaluation</h1>
            <p className="text-muted-foreground">ทดสอบและประเมินคุณภาพ Prompt และ Knowledge Base</p>
          </div>
          <Button onClick={() => setIsCreateOpen(true)}>
            <Plus className="h-4 w-4 mr-2" />
            สร้างการประเมิน
          </Button>
        </div>

        {/* Bot Selector */}
        <div className="mb-6">
          <Select value={selectedBotId ? String(selectedBotId) : ''} onValueChange={handleSelectBot}>
            <SelectTrigger className="w-full sm:w-64">
              <SelectValue placeholder="เลือก Bot" />
            </SelectTrigger>
            <SelectContent>
              {bots.map((bot) => (
                <SelectItem key={bot.id} value={String(bot.id)}>
                  {bot.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Evaluations List */}
        {isLoading ? (
          <div className="flex items-center justify-center py-12">
            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
          </div>
        ) : evaluations.length === 0 ? (
          <Card>
            <CardContent className="py-12 text-center">
              <Target className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
              <h3 className="text-lg font-medium mb-2">ยังไม่มีการประเมิน</h3>
              <p className="text-muted-foreground mb-4">
                เริ่มประเมินคุณภาพ Bot ของคุณด้วย AI
              </p>
              <Button onClick={() => setIsCreateOpen(true)}>
                <Plus className="h-4 w-4 mr-2" />
                สร้างการประเมินแรก
              </Button>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-4">
            {evaluations.map((evaluation) => (
              <EvaluationCard
                key={evaluation.id}
                evaluation={evaluation}
                onView={() => navigate(`/evaluations/${evaluation.id}?bot=${selectedBotId}`)}
                onCancel={() => handleCancel(evaluation.id)}
                onRetry={() => {/* TODO */}}
                onDelete={() => handleDelete(evaluation.id)}
                isDeleting={isDeleting}
                isCancelling={isCancelling}
              />
            ))}
          </div>
        )}
      </div>

      {/* Create Dialog */}
      {selectedBotId && (
        <CreateEvaluationDialog
          open={isCreateOpen}
          onOpenChange={setIsCreateOpen}
          botId={selectedBotId}
          onSuccess={refetch}
        />
      )}
    </div>
  );
}

export default EvaluationsPage;
