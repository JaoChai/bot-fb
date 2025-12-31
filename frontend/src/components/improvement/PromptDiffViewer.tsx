import { useMemo } from 'react';
import { cn } from '@/lib/utils';

interface PromptDiffViewerProps {
  currentValue: string;
  suggestedValue: string;
  diffSummary?: string | null;
}

// Simple diff algorithm for text comparison
function computeLineDiff(
  current: string,
  suggested: string
): { type: 'same' | 'removed' | 'added'; text: string }[] {
  const currentLines = current.split('\n');
  const suggestedLines = suggested.split('\n');
  const result: { type: 'same' | 'removed' | 'added'; text: string }[] = [];

  let i = 0;
  let j = 0;

  while (i < currentLines.length || j < suggestedLines.length) {
    if (i >= currentLines.length) {
      // Remaining lines in suggested are additions
      result.push({ type: 'added', text: suggestedLines[j] });
      j++;
    } else if (j >= suggestedLines.length) {
      // Remaining lines in current are removals
      result.push({ type: 'removed', text: currentLines[i] });
      i++;
    } else if (currentLines[i] === suggestedLines[j]) {
      // Lines match
      result.push({ type: 'same', text: currentLines[i] });
      i++;
      j++;
    } else {
      // Lines differ - show as removed then added
      result.push({ type: 'removed', text: currentLines[i] });
      result.push({ type: 'added', text: suggestedLines[j] });
      i++;
      j++;
    }
  }

  return result;
}

export function PromptDiffViewer({
  currentValue,
  suggestedValue,
  diffSummary,
}: PromptDiffViewerProps) {
  const diffLines = useMemo(
    () => computeLineDiff(currentValue, suggestedValue),
    [currentValue, suggestedValue]
  );

  return (
    <div className="space-y-2">
      {/* Diff summary */}
      {diffSummary && (
        <div className="text-xs text-muted-foreground bg-muted/50 rounded px-2 py-1">
          {diffSummary}
        </div>
      )}

      {/* Side-by-side diff */}
      <div className="grid grid-cols-2 gap-2">
        {/* Current */}
        <div className="space-y-1">
          <div className="text-xs font-medium text-muted-foreground">ปัจจุบัน</div>
          <div className="bg-muted/30 rounded-md p-2 text-xs font-mono max-h-48 overflow-y-auto">
            {currentValue.split('\n').map((line, idx) => (
              <div key={idx} className="whitespace-pre-wrap break-words">
                {line || '\u00A0'}
              </div>
            ))}
          </div>
        </div>

        {/* Suggested */}
        <div className="space-y-1">
          <div className="text-xs font-medium text-muted-foreground">แนะนำ</div>
          <div className="bg-muted/30 rounded-md p-2 text-xs font-mono max-h-48 overflow-y-auto">
            {suggestedValue.split('\n').map((line, idx) => (
              <div key={idx} className="whitespace-pre-wrap break-words">
                {line || '\u00A0'}
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Unified diff view */}
      <div className="space-y-1">
        <div className="text-xs font-medium text-muted-foreground">การเปลี่ยนแปลง</div>
        <div className="bg-muted/30 rounded-md p-2 text-xs font-mono max-h-48 overflow-y-auto">
          {diffLines.map((line, idx) => (
            <div
              key={idx}
              className={cn(
                'whitespace-pre-wrap break-words',
                line.type === 'removed' && 'bg-red-500/10 text-red-600 dark:text-red-400 line-through',
                line.type === 'added' && 'bg-green-500/10 text-green-600 dark:text-green-400'
              )}
            >
              {line.type === 'removed' && '- '}
              {line.type === 'added' && '+ '}
              {line.text || '\u00A0'}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
