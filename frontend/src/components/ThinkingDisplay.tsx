import { useState, useEffect } from 'react';
import { ChevronDown, ChevronUp, Brain, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';

interface ThinkingDisplayProps {
  thinking: string;
  isStreaming?: boolean;
}

export function ThinkingDisplay({ thinking, isStreaming }: ThinkingDisplayProps) {
  const [isExpanded, setIsExpanded] = useState(false);

  // Auto-expand when thinking content starts arriving
  useEffect(() => {
    if (thinking && !isExpanded) {
      setIsExpanded(true);
    }
  }, [thinking]);

  // Don't render if no thinking content and not streaming
  if (!thinking && !isStreaming) return null;

  return (
    <div className="mb-2">
      <Button
        variant="ghost"
        size="sm"
        className="w-full justify-between text-xs text-muted-foreground hover:bg-muted/50 px-2 py-1 h-auto"
        onClick={() => setIsExpanded(!isExpanded)}
      >
        <div className="flex items-center gap-1.5">
          <Brain className="h-3 w-3 text-purple-500" />
          <span>AI Thinking</span>
          {isStreaming && !thinking && (
            <Loader2 className="h-3 w-3 animate-spin text-purple-500" />
          )}
          {thinking && (
            <span className="text-purple-500">
              ({thinking.length} chars)
            </span>
          )}
        </div>
        {isExpanded ? (
          <ChevronUp className="h-3 w-3" />
        ) : (
          <ChevronDown className="h-3 w-3" />
        )}
      </Button>

      {isExpanded && (
        <div className="mt-1 p-2 bg-purple-500/10 border border-purple-500/20 rounded-md text-xs text-muted-foreground max-h-40 overflow-y-auto">
          <div className="font-mono whitespace-pre-wrap">
            {thinking || (isStreaming ? (
              <span className="flex items-center gap-1 text-purple-500">
                <Loader2 className="h-3 w-3 animate-spin" />
                Thinking...
              </span>
            ) : '')}
            {isStreaming && thinking && (
              <span className="animate-pulse text-purple-500">|</span>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
