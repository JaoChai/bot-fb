import { useState, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Sparkles, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  useImprovementOperations,
  useStartImprovement,
} from '@/hooks/useImprovement';
import type { ImprovementSession } from '@/types/api';
import { AnalyzingStep } from './AnalyzingStep';
import { ReviewSuggestionsStep } from './ReviewSuggestionsStep';
import { ApplyingStep } from './ApplyingStep';
import { CompletionStep } from './CompletionStep';

interface ImprovementAgentDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  botId: number;
  evaluationId: number;
  evaluationName: string;
}

type DialogStep = 'idle' | 'analyzing' | 'review' | 'applying' | 'complete';

// Map session status to dialog step
function getDialogStep(status: ImprovementSession['status'] | null): DialogStep {
  if (!status) return 'idle';
  switch (status) {
    case 'analyzing':
      return 'analyzing';
    case 'suggestions_ready':
      return 'review';
    case 'applying':
    case 're_evaluating':
      return 'applying';
    case 'completed':
      return 'complete';
    case 'failed':
    case 'cancelled':
      return 'idle';
    default:
      return 'idle';
  }
}

// Step indicator configuration
const STEPS = [
  { key: 'analyzing', label: 'วิเคราะห์' },
  { key: 'review', label: 'ตรวจสอบ' },
  { key: 'applying', label: 'ดำเนินการ' },
  { key: 'complete', label: 'เสร็จสิ้น' },
];

type StepState = 'completed' | 'current' | 'pending';

function getStepIndex(step: DialogStep): number {
  const index = STEPS.findIndex((s) => s.key === step);
  return index >= 0 ? index : 0;
}

function getStepState(stepIndex: number, currentStepIndex: number): StepState {
  if (stepIndex < currentStepIndex) return 'completed';
  if (stepIndex === currentStepIndex) return 'current';
  return 'pending';
}

export function ImprovementAgentDialog({
  open,
  onOpenChange,
  botId,
  evaluationId,
  evaluationName,
}: ImprovementAgentDialogProps) {
  const [sessionId, setSessionId] = useState<number | null>(null);

  const startMutation = useStartImprovement(botId);
  const {
    session,
    suggestions,
    selectedCount,
    isApplying,
    isCancelling,
    canCancel,
    toggleSuggestion,
    applyImprovements,
    cancelImprovement,
  } = useImprovementOperations(botId, sessionId);

  const currentStep = getDialogStep(session?.status ?? null);
  const currentStepIndex = getStepIndex(currentStep);

  // Reset session when dialog closes
  useEffect(() => {
    if (!open) {
      setSessionId(null);
    }
  }, [open]);

  // Start improvement session when dialog opens
  const handleStart = async () => {
    try {
      const result = await startMutation.mutateAsync(evaluationId);
      setSessionId(result.id);
    } catch (error) {
      console.error('Failed to start improvement session:', error);
    }
  };

  // Toggle suggestion selection
  const handleToggle = async (suggestionId: number, isSelected: boolean) => {
    try {
      await toggleSuggestion({ suggestionId, isSelected });
    } catch (error) {
      console.error('Failed to toggle suggestion:', error);
    }
  };

  // Apply selected improvements
  const handleApply = async () => {
    try {
      await applyImprovements();
    } catch (error) {
      console.error('Failed to apply improvements:', error);
    }
  };

  // Cancel improvement session
  const handleCancel = async () => {
    if (sessionId && canCancel) {
      try {
        await cancelImprovement();
        onOpenChange(false);
      } catch (error) {
        console.error('Failed to cancel improvement:', error);
      }
    } else {
      onOpenChange(false);
    }
  };

  // Render step content
  const renderStepContent = () => {
    if (currentStep === 'idle') {
      return (
        <div className="flex flex-col items-center justify-center py-8 text-center">
          <Sparkles className="h-12 w-12 text-primary mb-4" />
          <h3 className="text-lg font-semibold mb-2">AI Improvement Agent</h3>
          <p className="text-muted-foreground mb-6 max-w-md">
            Agent จะวิเคราะห์ผลประเมิน &quot;{evaluationName}&quot; และเสนอแนะการปรับปรุง
            System Prompt และ Knowledge Base ให้อัตโนมัติ
          </p>
          <Button
            onClick={handleStart}
            disabled={startMutation.isPending}
            className="gap-2"
          >
            {startMutation.isPending ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Sparkles className="h-4 w-4" />
            )}
            เริ่มวิเคราะห์
          </Button>
        </div>
      );
    }

    if (currentStep === 'analyzing') {
      return <AnalyzingStep session={session} />;
    }

    if (currentStep === 'review') {
      return (
        <ReviewSuggestionsStep
          suggestions={suggestions}
          selectedCount={selectedCount}
          onToggle={handleToggle}
        />
      );
    }

    if (currentStep === 'applying') {
      return <ApplyingStep session={session} suggestions={suggestions} />;
    }

    if (currentStep === 'complete') {
      return (
        <CompletionStep
          session={session}
          suggestions={suggestions}
          onViewEvaluation={() => {
            // Navigate to new evaluation when available
            if (session?.re_evaluation_id) {
              window.location.href = `/bots/${botId}/evaluations/${session.re_evaluation_id}`;
            }
          }}
        />
      );
    }

    return null;
  };

  // Render footer buttons
  const renderFooter = () => {
    if (currentStep === 'idle') {
      return (
        <Button variant="outline" onClick={() => onOpenChange(false)}>
          ยกเลิก
        </Button>
      );
    }

    if (currentStep === 'analyzing') {
      return (
        <Button
          variant="outline"
          onClick={handleCancel}
          disabled={isCancelling}
        >
          {isCancelling && <Loader2 className="h-4 w-4 animate-spin mr-2" />}
          ยกเลิก
        </Button>
      );
    }

    if (currentStep === 'review') {
      return (
        <div className="flex gap-2">
          <Button
            variant="outline"
            onClick={handleCancel}
            disabled={isCancelling}
          >
            ยกเลิก
          </Button>
          <Button
            onClick={handleApply}
            disabled={isApplying || selectedCount === 0}
            className="gap-2"
          >
            {isApplying ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Sparkles className="h-4 w-4" />
            )}
            ดำเนินการ {selectedCount} รายการ
          </Button>
        </div>
      );
    }

    if (currentStep === 'applying') {
      return (
        <p className="text-sm text-muted-foreground">
          กรุณาอย่าปิดหน้าต่างนี้
        </p>
      );
    }

    if (currentStep === 'complete') {
      return (
        <Button onClick={() => onOpenChange(false)}>ปิด</Button>
      );
    }

    return null;
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Sparkles className="h-5 w-5 text-primary" />
            AI Improvement Agent
          </DialogTitle>
          <DialogDescription>
            วิเคราะห์และปรับปรุง Bot จากผลประเมินอัตโนมัติ
          </DialogDescription>
        </DialogHeader>

        {/* Step Indicator - only show when session is active */}
        {currentStep !== 'idle' && (
          <div className="flex items-center justify-between px-4 py-2 border-b">
            {STEPS.map((step, index) => {
              const stepState = getStepState(index, currentStepIndex);
              const isLast = index === STEPS.length - 1;

              return (
                <div key={step.key} className="flex items-center flex-1">
                  <div className="flex flex-col items-center">
                    <div
                      className={cn(
                        'w-7 h-7 rounded-full flex items-center justify-center text-xs font-medium transition-all',
                        stepState === 'completed' && 'bg-green-500 text-white',
                        stepState === 'current' && 'bg-primary text-primary-foreground',
                        stepState === 'pending' && 'bg-muted text-muted-foreground'
                      )}
                    >
                      {index + 1}
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
                  {!isLast && (
                    <div
                      className={cn(
                        'flex-1 h-0.5 mx-2 mt-[-14px]',
                        stepState === 'completed' ? 'bg-green-500' : 'bg-muted'
                      )}
                    />
                  )}
                </div>
              );
            })}
          </div>
        )}

        {/* Step Content */}
        <ScrollArea className="flex-1 max-h-[400px]">
          <div className="p-4">{renderStepContent()}</div>
        </ScrollArea>

        {/* Footer */}
        <div className="flex justify-end items-center px-4 py-3 border-t">
          {renderFooter()}
        </div>
      </DialogContent>
    </Dialog>
  );
}
