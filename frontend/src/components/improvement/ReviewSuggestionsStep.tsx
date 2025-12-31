import { useMemo } from 'react';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Button } from '@/components/ui/button';
import { ChevronDown, FileText, BookOpen } from 'lucide-react';
import type { ImprovementSuggestion } from '@/types/api';
import { SuggestionCard } from './SuggestionCard';

interface ReviewSuggestionsStepProps {
  suggestions: ImprovementSuggestion[];
  selectedCount: number;
  onToggle: (suggestionId: number, isSelected: boolean) => void;
}

export function ReviewSuggestionsStep({
  suggestions,
  selectedCount,
  onToggle,
}: ReviewSuggestionsStepProps) {
  // Group suggestions by type
  const groupedSuggestions = useMemo(() => {
    const groups = {
      system_prompt: [] as ImprovementSuggestion[],
      kb_content: [] as ImprovementSuggestion[],
    };

    suggestions.forEach((s) => {
      if (s.type === 'system_prompt') {
        groups.system_prompt.push(s);
      } else if (s.type === 'kb_content') {
        groups.kb_content.push(s);
      }
    });

    return groups;
  }, [suggestions]);

  const hasPromptSuggestions = groupedSuggestions.system_prompt.length > 0;
  const hasKbSuggestions = groupedSuggestions.kb_content.length > 0;

  if (suggestions.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-8 text-center">
        <div className="text-muted-foreground">
          ไม่พบข้อเสนอแนะสำหรับการปรับปรุง
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Summary */}
      <div className="flex items-center justify-between">
        <span className="text-sm text-muted-foreground">
          พบ {suggestions.length} ข้อเสนอแนะ (เลือก {selectedCount} รายการ)
        </span>
      </div>

      {/* System Prompt Suggestions */}
      {hasPromptSuggestions && (
        <Collapsible defaultOpen>
          <CollapsibleTrigger asChild>
            <Button
              variant="ghost"
              className="w-full justify-between h-auto py-2 px-3"
            >
              <div className="flex items-center gap-2">
                <FileText className="h-4 w-4 text-primary" />
                <span className="font-medium">
                  System Prompt ({groupedSuggestions.system_prompt.length} รายการ)
                </span>
              </div>
              <ChevronDown className="h-4 w-4 transition-transform duration-200 group-data-[state=open]:rotate-180" />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="mt-2 space-y-2">
            {groupedSuggestions.system_prompt.map((suggestion) => (
              <SuggestionCard
                key={suggestion.id}
                suggestion={suggestion}
                onToggle={onToggle}
              />
            ))}
          </CollapsibleContent>
        </Collapsible>
      )}

      {/* KB Content Suggestions */}
      {hasKbSuggestions && (
        <Collapsible defaultOpen>
          <CollapsibleTrigger asChild>
            <Button
              variant="ghost"
              className="w-full justify-between h-auto py-2 px-3"
            >
              <div className="flex items-center gap-2">
                <BookOpen className="h-4 w-4 text-primary" />
                <span className="font-medium">
                  Knowledge Base ({groupedSuggestions.kb_content.length} รายการ)
                </span>
              </div>
              <ChevronDown className="h-4 w-4 transition-transform duration-200 group-data-[state=open]:rotate-180" />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="mt-2 space-y-2">
            {groupedSuggestions.kb_content.map((suggestion) => (
              <SuggestionCard
                key={suggestion.id}
                suggestion={suggestion}
                onToggle={onToggle}
              />
            ))}
          </CollapsibleContent>
        </Collapsible>
      )}
    </div>
  );
}
