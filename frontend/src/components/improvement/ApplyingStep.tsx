import { useMemo } from 'react';
import { Progress } from '@/components/ui/progress';
import { Loader2, Check, Circle, FileText, BookOpen, RefreshCw } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ImprovementSession, ImprovementSuggestion } from '@/types/api';

interface ApplyingStepProps {
  session: ImprovementSession | undefined;
  suggestions: ImprovementSuggestion[];
}

interface ApplyPhase {
  key: string;
  label: string;
  icon: typeof FileText;
}

export function ApplyingStep({ session, suggestions }: ApplyingStepProps) {
  // Build dynamic phases based on selected suggestions
  const phases = useMemo(() => {
    const result: ApplyPhase[] = [];
    const selectedSuggestions = suggestions.filter((s) => s.is_selected);

    const hasPromptSuggestion = selectedSuggestions.some(
      (s) => s.type === 'system_prompt'
    );
    const kbSuggestions = selectedSuggestions.filter(
      (s) => s.type === 'kb_content'
    );

    if (hasPromptSuggestion) {
      result.push({
        key: 'prompt',
        label: 'อัพเดต System Prompt',
        icon: FileText,
      });
    }

    kbSuggestions.forEach((kb, index) => {
      result.push({
        key: `kb_${kb.id}`,
        label: `สร้างเอกสาร "${kb.kb_content_title || `KB ${index + 1}`}"`,
        icon: BookOpen,
      });
    });

    result.push({
      key: 're_eval',
      label: 'เริ่ม Re-evaluation',
      icon: RefreshCw,
    });

    return result;
  }, [suggestions]);

  // Estimate current phase based on session status and applied suggestions
  const currentPhase = useMemo(() => {
    if (!session) return 0;

    if (session.status === 're_evaluating') {
      return phases.length; // Last phase (re-eval) is in progress
    }

    // Count applied suggestions to estimate progress
    const appliedCount = suggestions.filter(
      (s) => s.is_selected && s.is_applied
    ).length;
    const totalSelected = suggestions.filter((s) => s.is_selected).length;

    if (totalSelected === 0) return 1;

    // Rough estimation
    const promptApplied = suggestions.some(
      (s) => s.type === 'system_prompt' && s.is_selected && s.is_applied
    );
    const hasPrompt = suggestions.some(
      (s) => s.type === 'system_prompt' && s.is_selected
    );

    if (hasPrompt && !promptApplied) return 1;
    if (hasPrompt && promptApplied) return 1 + appliedCount;
    return appliedCount + 1;
  }, [session, suggestions, phases.length]);

  // Calculate progress percentage
  const percent = Math.min(
    Math.round((currentPhase / phases.length) * 100),
    95
  );

  type PhaseState = 'completed' | 'current' | 'pending';

  function getPhaseState(phaseIndex: number): PhaseState {
    const phaseNumber = phaseIndex + 1;
    if (phaseNumber < currentPhase) return 'completed';
    if (phaseNumber === currentPhase) return 'current';
    return 'pending';
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex items-center gap-2 text-sm font-medium">
        <Loader2 className="h-4 w-4 animate-spin text-primary" />
        <span className="text-foreground">กำลังดำเนินการ...</span>
      </div>

      {/* Phase list */}
      <div className="bg-muted/50 rounded-lg p-4 space-y-3">
        {phases.map((phase, index) => {
          const phaseState = getPhaseState(index);
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
              <Icon
                className={cn(
                  'h-4 w-4',
                  phaseState === 'completed' && 'text-green-500',
                  phaseState === 'current' && 'text-primary',
                  phaseState === 'pending' && 'text-muted-foreground'
                )}
              />
              <span className="truncate">{phase.label}</span>
            </div>
          );
        })}
      </div>

      {/* Progress bar */}
      <div className="space-y-1.5">
        <Progress value={percent} className="h-2" />
        <div className="flex justify-between text-xs text-muted-foreground">
          <span>{percent}%</span>
        </div>
      </div>

      {/* Warning message */}
      <div className="flex items-center justify-center gap-2 text-xs text-amber-600 dark:text-amber-400 bg-amber-500/10 rounded-md p-2">
        <span>กรุณาอย่าปิดหน้าต่างนี้ระหว่างดำเนินการ</span>
      </div>
    </div>
  );
}
