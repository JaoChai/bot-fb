import { useState } from 'react';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ChevronDown, Star, FileText, BookOpen } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ImprovementSuggestion } from '@/types/api';
import { PromptDiffViewer } from './PromptDiffViewer';

interface SuggestionCardProps {
  suggestion: ImprovementSuggestion;
  onToggle: (suggestionId: number, isSelected: boolean) => void;
  disabled?: boolean;
}

// Priority badge variants
const PRIORITY_CONFIG = {
  high: { label: 'สูง', variant: 'destructive' as const },
  medium: { label: 'กลาง', variant: 'default' as const },
  low: { label: 'ต่ำ', variant: 'secondary' as const },
};

// Type icons
const TYPE_ICONS = {
  system_prompt: FileText,
  kb_content: BookOpen,
};

export function SuggestionCard({
  suggestion,
  onToggle,
  disabled = false,
}: SuggestionCardProps) {
  const [isExpanded, setIsExpanded] = useState(false);

  const priorityConfig = PRIORITY_CONFIG[suggestion.priority];
  const TypeIcon = TYPE_ICONS[suggestion.type];
  const confidencePercent = Math.round((suggestion.confidence_score || 0) * 100);

  return (
    <Card
      className={cn(
        'p-3 transition-all cursor-pointer',
        suggestion.is_selected
          ? 'border-primary bg-primary/5'
          : 'border-border hover:border-muted-foreground/50'
      )}
      onClick={() => !disabled && onToggle(suggestion.id, !suggestion.is_selected)}
    >
      <div className="flex items-start gap-3">
        {/* Checkbox */}
        <div className="pt-0.5">
          <div
            className={cn(
              'w-5 h-5 rounded border-2 flex items-center justify-center transition-all',
              suggestion.is_selected
                ? 'bg-primary border-primary text-primary-foreground'
                : 'border-muted-foreground/50'
            )}
          >
            {suggestion.is_selected && (
              <svg
                className="w-3 h-3"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={3}
                  d="M5 13l4 4L19 7"
                />
              </svg>
            )}
          </div>
        </div>

        {/* Content */}
        <div className="flex-1 min-w-0" onClick={(e) => e.stopPropagation()}>
          {/* Header */}
          <div className="flex items-center gap-2 mb-1">
            <TypeIcon className="h-4 w-4 text-muted-foreground" />
            <span className="font-medium text-sm">{suggestion.title}</span>
          </div>

          {/* Badges */}
          <div className="flex items-center gap-2 mb-2">
            <Badge variant={priorityConfig.variant} className="text-xs">
              {priorityConfig.label}
            </Badge>
            <div className="flex items-center gap-1 text-xs text-muted-foreground">
              <Star className="h-3 w-3" />
              <span>{confidencePercent}%</span>
            </div>
            {suggestion.source_metric && (
              <span className="text-xs text-muted-foreground">
                {suggestion.source_metric}
              </span>
            )}
          </div>

          {/* Description */}
          {suggestion.description && (
            <p className="text-sm text-muted-foreground mb-2">
              {suggestion.description}
            </p>
          )}

          {/* Expandable content */}
          {(suggestion.type === 'system_prompt' && suggestion.suggested_value) ||
          (suggestion.type === 'kb_content' && suggestion.kb_content_body) ? (
            <Collapsible open={isExpanded} onOpenChange={setIsExpanded}>
              <CollapsibleTrigger asChild>
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 px-2 text-xs gap-1"
                >
                  <ChevronDown
                    className={cn(
                      'h-3 w-3 transition-transform',
                      isExpanded && 'rotate-180'
                    )}
                  />
                  {suggestion.type === 'system_prompt' ? 'ดู Diff' : 'ดูเนื้อหา'}
                </Button>
              </CollapsibleTrigger>
              <CollapsibleContent className="mt-2">
                {suggestion.type === 'system_prompt' ? (
                  <PromptDiffViewer
                    currentValue={suggestion.current_value || ''}
                    suggestedValue={suggestion.suggested_value || ''}
                    diffSummary={suggestion.diff_summary}
                  />
                ) : (
                  <div className="bg-muted/50 rounded-md p-3">
                    <div className="text-sm font-medium mb-1">
                      {suggestion.kb_content_title}
                    </div>
                    <div className="text-sm text-muted-foreground whitespace-pre-wrap max-h-40 overflow-y-auto">
                      {suggestion.kb_content_body}
                    </div>
                    {suggestion.related_topics && suggestion.related_topics.length > 0 && (
                      <div className="flex flex-wrap gap-1 mt-2">
                        {suggestion.related_topics.map((topic, i) => (
                          <Badge key={i} variant="outline" className="text-xs">
                            {topic}
                          </Badge>
                        ))}
                      </div>
                    )}
                  </div>
                )}
              </CollapsibleContent>
            </Collapsible>
          ) : null}
        </div>
      </div>
    </Card>
  );
}
