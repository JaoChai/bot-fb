import { useState } from 'react';
import { Brain, ChevronDown, ChevronRight, Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

interface ThinkingDisplayProps {
  thinking: string;
  isStreaming?: boolean;
  defaultOpen?: boolean;
  className?: string;
}

export function ThinkingDisplay({
  thinking,
  isStreaming = false,
  defaultOpen = true,
  className,
}: ThinkingDisplayProps) {
  const [isOpen, setIsOpen] = useState(defaultOpen);

  if (!thinking && !isStreaming) return null;

  return (
    <div className={cn('border-b border-border/50', className)}>
      <button
        type="button"
        onClick={() => setIsOpen(!isOpen)}
        className="flex w-full items-center gap-2 px-3 py-2 text-xs text-muted-foreground hover:bg-muted/50 transition-colors"
      >
        <Brain className="h-3.5 w-3.5 text-purple-500" />
        <span className="font-medium">AI Thinking</span>
        {isStreaming && <Loader2 className="h-3 w-3 animate-spin text-purple-500" />}
        <div className="ml-auto">
          {isOpen ? (
            <ChevronDown className="h-3.5 w-3.5" />
          ) : (
            <ChevronRight className="h-3.5 w-3.5" />
          )}
        </div>
      </button>

      {isOpen && (
        <div className="px-3 pb-3">
          <div className="rounded-md bg-purple-500/5 border border-purple-500/20 p-3">
            <pre className="font-mono text-xs whitespace-pre-wrap text-purple-200/80 leading-relaxed">
              {thinking || (isStreaming ? '...' : '')}
              {isStreaming && <span className="animate-pulse">|</span>}
            </pre>
          </div>
        </div>
      )}
    </div>
  );
}
