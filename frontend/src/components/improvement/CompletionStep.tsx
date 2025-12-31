import { useMemo } from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { CheckCircle2, ArrowUp, ArrowDown, Minus, FileText, BookOpen, ExternalLink } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ImprovementSession, ImprovementSuggestion } from '@/types/api';

interface CompletionStepProps {
  session: ImprovementSession | undefined;
  suggestions: ImprovementSuggestion[];
  onViewEvaluation: () => void;
}

export function CompletionStep({
  session,
  suggestions,
  onViewEvaluation,
}: CompletionStepProps) {
  // Calculate score data
  const scoreData = useMemo(() => {
    const beforeScore = session?.before_score ?? 0;
    const afterScore = session?.after_score ?? beforeScore;
    const improvement = session?.score_improvement ?? (afterScore - beforeScore);
    const improvementPercent = beforeScore > 0
      ? Math.round((improvement / beforeScore) * 100)
      : 0;

    return {
      before: beforeScore,
      after: afterScore,
      improvement,
      improvementPercent,
      isImproved: improvement > 0,
      isRegressed: improvement < 0,
    };
  }, [session]);

  // Count applied changes by type
  const appliedChanges = useMemo(() => {
    const applied = suggestions.filter((s) => s.is_applied);
    return {
      promptCount: applied.filter((s) => s.type === 'system_prompt').length,
      kbCount: applied.filter((s) => s.type === 'kb_content').length,
      total: applied.length,
    };
  }, [suggestions]);

  // Determine trend icon
  const TrendIcon = scoreData.isImproved
    ? ArrowUp
    : scoreData.isRegressed
    ? ArrowDown
    : Minus;

  return (
    <div className="space-y-6">
      {/* Success header */}
      <div className="flex flex-col items-center text-center">
        <CheckCircle2 className="h-12 w-12 text-green-500 mb-3" />
        <h3 className="text-lg font-semibold">ปรับปรุงเสร็จสมบูรณ์!</h3>
        <p className="text-sm text-muted-foreground">
          ระบบได้ทำการปรับปรุงและประเมินผลใหม่เรียบร้อยแล้ว
        </p>
      </div>

      {/* Score comparison */}
      <Card className="p-4">
        <div className="text-sm font-medium text-muted-foreground mb-3 text-center">
          Score Comparison
        </div>
        <div className="grid grid-cols-3 gap-4">
          {/* Before */}
          <div className="text-center">
            <div className="text-xs text-muted-foreground mb-1">ก่อน</div>
            <div className="text-2xl font-bold">
              {(scoreData.before * 100).toFixed(0)}%
            </div>
            <Progress value={scoreData.before * 100} className="h-1.5 mt-2" />
          </div>

          {/* After */}
          <div className="text-center">
            <div className="text-xs text-muted-foreground mb-1">หลัง</div>
            <div className="text-2xl font-bold text-primary">
              {(scoreData.after * 100).toFixed(0)}%
            </div>
            <Progress value={scoreData.after * 100} className="h-1.5 mt-2" />
          </div>

          {/* Change */}
          <div className="text-center">
            <div className="text-xs text-muted-foreground mb-1">เปลี่ยนแปลง</div>
            <div
              className={cn(
                'text-2xl font-bold flex items-center justify-center gap-1',
                scoreData.isImproved && 'text-green-500',
                scoreData.isRegressed && 'text-red-500',
                !scoreData.isImproved && !scoreData.isRegressed && 'text-muted-foreground'
              )}
            >
              <TrendIcon className="h-5 w-5" />
              {scoreData.improvement >= 0 ? '+' : ''}
              {(scoreData.improvement * 100).toFixed(0)}%
            </div>
            {scoreData.improvementPercent !== 0 && (
              <div
                className={cn(
                  'text-xs mt-1',
                  scoreData.isImproved && 'text-green-500',
                  scoreData.isRegressed && 'text-red-500'
                )}
              >
                ({scoreData.improvementPercent > 0 ? '+' : ''}
                {scoreData.improvementPercent}% จากเดิม)
              </div>
            )}
          </div>
        </div>
      </Card>

      {/* Applied changes summary */}
      <div className="space-y-2">
        <div className="text-sm font-medium">สิ่งที่เปลี่ยนแปลง:</div>
        <div className="space-y-1.5">
          {appliedChanges.promptCount > 0 && (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <FileText className="h-4 w-4" />
              <span>อัพเดต System Prompt ({appliedChanges.promptCount} รายการ)</span>
            </div>
          )}
          {appliedChanges.kbCount > 0 && (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <BookOpen className="h-4 w-4" />
              <span>สร้างเอกสารใน KB ({appliedChanges.kbCount} รายการ)</span>
            </div>
          )}
        </div>
      </div>

      {/* View evaluation button */}
      {session?.re_evaluation_id && (
        <Button
          onClick={onViewEvaluation}
          variant="outline"
          className="w-full gap-2"
        >
          <ExternalLink className="h-4 w-4" />
          ดูผลประเมินใหม่
        </Button>
      )}
    </div>
  );
}
