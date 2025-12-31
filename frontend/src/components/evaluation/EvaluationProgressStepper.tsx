import { cn } from '@/lib/utils';
import { Progress } from '@/components/ui/progress';
import {
  Sparkles,
  MessageSquare,
  Target,
  FileText,
  Check,
  Loader2,
} from 'lucide-react';

interface EvaluationProgressStepperProps {
  status: string;
  completedTestCases: number;
  totalTestCases: number;
  percent: number;
}

// Step configuration
const STEPS = [
  { key: 'generate', label: 'สร้าง', icon: Sparkles, statusMatch: 'generating_tests' },
  { key: 'simulate', label: 'จำลอง', icon: MessageSquare, statusMatch: 'running' },
  { key: 'evaluate', label: 'ประเมิน', icon: Target, statusMatch: 'evaluating' },
  { key: 'report', label: 'รายงาน', icon: FileText, statusMatch: 'generating_report' },
];

// Map status to current step (1-indexed, 0 = pending)
const STATUS_TO_STEP: Record<string, number> = {
  pending: 0,
  generating_tests: 1,
  running: 2,
  evaluating: 3,
  generating_report: 4,
  completed: 5,
  failed: -1,
};

// Phase labels in Thai
const PHASE_LABELS: Record<string, string> = {
  pending: 'รอดำเนินการ...',
  generating_tests: 'กำลังสร้าง test cases...',
  running: 'กำลังจำลองบทสนทนา...',
  evaluating: 'กำลังประเมินผล...',
  generating_report: 'กำลังสร้างรายงาน...',
};

type StepState = 'completed' | 'current' | 'pending';

function getStepState(stepIndex: number, currentStep: number): StepState {
  if (stepIndex < currentStep) return 'completed';
  if (stepIndex === currentStep) return 'current';
  return 'pending';
}

export function EvaluationProgressStepper({
  status,
  completedTestCases,
  totalTestCases,
  percent,
}: EvaluationProgressStepperProps) {
  const currentStep = STATUS_TO_STEP[status] ?? 0;
  const phaseLabel = PHASE_LABELS[status] ?? status;

  return (
    <div className="space-y-3">
      {/* Phase label with spinner */}
      <div className="flex items-center gap-2 text-sm font-medium">
        <Loader2 className="h-4 w-4 animate-spin text-primary" />
        <span className="text-foreground">{phaseLabel}</span>
      </div>

      {/* Progress bar with percentage */}
      <div className="space-y-1.5">
        <Progress value={percent} className="h-2" />
        <div className="flex justify-between text-xs text-muted-foreground">
          <span>{percent.toFixed(0)}%</span>
          <span>{completedTestCases} / {totalTestCases}</span>
        </div>
      </div>

      {/* Step indicator */}
      <div className="flex items-center justify-between pt-1">
        {STEPS.map((step, index) => {
          const stepNumber = index + 1;
          const stepState = getStepState(stepNumber, currentStep);
          const Icon = step.icon;
          const isLast = index === STEPS.length - 1;

          return (
            <div key={step.key} className="flex items-center flex-1">
              {/* Step circle and label */}
              <div className="flex flex-col items-center">
                <div
                  className={cn(
                    'w-7 h-7 rounded-full flex items-center justify-center transition-all',
                    stepState === 'completed' && 'bg-green-500 text-white',
                    stepState === 'current' && 'bg-primary text-primary-foreground animate-pulse',
                    stepState === 'pending' && 'bg-muted text-muted-foreground'
                  )}
                >
                  {stepState === 'completed' ? (
                    <Check className="h-3.5 w-3.5" />
                  ) : (
                    <Icon className="h-3.5 w-3.5" />
                  )}
                </div>
                <span
                  className={cn(
                    'text-[10px] mt-1 font-medium',
                    stepState === 'completed' && 'text-green-600 dark:text-green-400',
                    stepState === 'current' && 'text-primary',
                    stepState === 'pending' && 'text-muted-foreground'
                  )}
                >
                  {step.label}
                </span>
              </div>

              {/* Connecting line */}
              {!isLast && (
                <div
                  className={cn(
                    'flex-1 h-0.5 mx-1.5 mt-[-14px]',
                    stepState === 'completed' ? 'bg-green-500' : 'bg-muted'
                  )}
                />
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
