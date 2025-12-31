import { Progress } from '@/components/ui/progress';
import { Loader2, Check, Circle, Sparkles, FileSearch, Lightbulb, BookOpen } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ImprovementSession } from '@/types/api';

interface AnalyzingStepProps {
  session: ImprovementSession | undefined;
}

// Analysis phases
const ANALYSIS_PHASES = [
  { key: 'load', label: 'โหลดข้อมูล Evaluation Report', icon: FileSearch },
  { key: 'analyze', label: 'วิเคราะห์จุดอ่อน (Weaknesses)', icon: Sparkles },
  { key: 'prompt', label: 'สร้าง Prompt Suggestions', icon: Lightbulb },
  { key: 'kb', label: 'สร้าง KB Suggestions', icon: BookOpen },
];

// Estimate progress based on status (since we don't have real progress tracking)
function estimateProgress(session: ImprovementSession | undefined): {
  percent: number;
  currentPhase: number;
} {
  if (!session || session.status !== 'analyzing') {
    return { percent: 0, currentPhase: 0 };
  }

  // Estimate based on time or tokens used
  const tokensUsed = session.total_tokens_used || 0;

  // Rough estimation: expect ~2000 tokens per phase
  if (tokensUsed < 500) return { percent: 15, currentPhase: 1 };
  if (tokensUsed < 1500) return { percent: 40, currentPhase: 2 };
  if (tokensUsed < 3000) return { percent: 65, currentPhase: 3 };
  return { percent: 85, currentPhase: 4 };
}

type PhaseState = 'completed' | 'current' | 'pending';

function getPhaseState(phaseIndex: number, currentPhase: number): PhaseState {
  if (phaseIndex < currentPhase) return 'completed';
  if (phaseIndex === currentPhase) return 'current';
  return 'pending';
}

export function AnalyzingStep({ session }: AnalyzingStepProps) {
  const { percent, currentPhase } = estimateProgress(session);

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center gap-2 text-sm font-medium">
        <Loader2 className="h-4 w-4 animate-spin text-primary" />
        <span className="text-foreground">กำลังวิเคราะห์ผลประเมิน...</span>
      </div>

      {/* Phase list */}
      <div className="bg-muted/50 rounded-lg p-4 space-y-3">
        {ANALYSIS_PHASES.map((phase, index) => {
          const phaseNumber = index + 1;
          const phaseState = getPhaseState(phaseNumber, currentPhase);
          const Icon = phase.icon;

          return (
            <div
              key={phase.key}
              className={cn(
                'flex items-center gap-3 text-sm transition-all',
                phaseState === 'completed' && 'text-green-600 dark:text-green-400',
                phaseState === 'current' && 'text-foreground',
                phaseState === 'pending' && 'text-muted-foreground'
              )}
            >
              {/* Status icon */}
              <div className="w-5 h-5 flex items-center justify-center">
                {phaseState === 'completed' && (
                  <Check className="h-4 w-4 text-green-500" />
                )}
                {phaseState === 'current' && (
                  <Loader2 className="h-4 w-4 animate-spin text-primary" />
                )}
                {phaseState === 'pending' && (
                  <Circle className="h-4 w-4 text-muted-foreground" />
                )}
              </div>

              {/* Phase icon and label */}
              <Icon className={cn(
                'h-4 w-4',
                phaseState === 'completed' && 'text-green-500',
                phaseState === 'current' && 'text-primary',
                phaseState === 'pending' && 'text-muted-foreground'
              )} />
              <span>{phase.label}</span>
            </div>
          );
        })}
      </div>

      {/* Progress bar */}
      <div className="space-y-1.5">
        <Progress value={percent} className="h-2" />
        <div className="flex justify-between text-xs text-muted-foreground">
          <span>{percent}%</span>
          {session?.total_tokens_used && session.total_tokens_used > 0 && (
            <span>{session.total_tokens_used.toLocaleString()} tokens</span>
          )}
        </div>
      </div>

      {/* Info message */}
      <p className="text-xs text-muted-foreground text-center">
        Agent กำลังวิเคราะห์ test cases ที่คะแนนต่ำและสร้างข้อเสนอแนะ...
      </p>
    </div>
  );
}
