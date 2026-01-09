/**
 * KnowledgeBaseSection - Knowledge base attachment for flow editor
 * Part of 006-bots-refactor feature (T049)
 *
 * Controls:
 * - knowledge_bases array management
 * - Add/remove knowledge bases
 * - Per-KB settings: kb_top_k, kb_similarity_threshold
 *
 * Copied from frontend and adapted for Inertia context
 * TODO: Replace with Inertia patterns for data fetching
 */

import { memo, useMemo, useCallback } from 'react';
import { Label } from '@/Components/ui/label';
import { Slider } from '@/Components/ui/slider';
import { Badge } from '@/Components/ui/badge';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/Components/ui/tooltip';
import { BookOpen, X, Loader2, Info, Database, Search } from 'lucide-react';
import {
  type KnowledgeBaseSectionProps,
  type FlowKnowledgeBase,
  type KnowledgeBaseOption,
} from './types';

const InfoTooltip = ({ children }: { children: React.ReactNode }) => (
  <TooltipProvider>
    <Tooltip>
      <TooltipTrigger asChild>
        <Info className="h-4 w-4 text-muted-foreground cursor-help" />
      </TooltipTrigger>
      <TooltipContent className="max-w-xs">
        <p className="text-sm">{children}</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
);

// Individual KB selection item (memoized)
const KnowledgeBaseItem = memo(function KnowledgeBaseItem({
  kb,
  isSelected,
  onToggle,
  disabled,
}: {
  kb: KnowledgeBaseOption;
  isSelected: boolean;
  onToggle: (id: number, checked: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <label
      className={`flex items-center gap-3 p-3 rounded-lg cursor-pointer transition-colors ${
        isSelected ? 'bg-primary/10 border border-primary/30' : 'hover:bg-muted border border-transparent'
      } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
    >
      <input
        type="checkbox"
        checked={isSelected}
        onChange={(e) => onToggle(kb.id, e.target.checked)}
        disabled={disabled}
        className="rounded border-border"
      />
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <Database className="h-4 w-4 text-muted-foreground" />
          <span className="font-medium text-sm truncate">{kb.name}</span>
        </div>
        <div className="text-xs text-muted-foreground mt-1">
          {kb.document_count} documents
        </div>
      </div>
    </label>
  );
});

// KB configuration item (memoized)
const KnowledgeBaseConfigItem = memo(function KnowledgeBaseConfigItem({
  config,
  name,
  onRemove,
  onConfigChange,
  disabled,
}: {
  config: FlowKnowledgeBase;
  name: string;
  onRemove: (id: number) => void;
  onConfigChange: (id: number, field: 'kb_top_k' | 'kb_similarity_threshold', value: number) => void;
  disabled?: boolean;
}) {
  return (
    <div className="border rounded-lg p-4 space-y-4 bg-muted/20">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Database className="h-4 w-4 text-primary" />
          <span className="text-sm font-medium">{name}</span>
        </div>
        <button
          onClick={() => onRemove(config.id)}
          disabled={disabled}
          className="text-muted-foreground hover:text-destructive transition-colors disabled:opacity-50"
          title="Remove knowledge base"
        >
          <X className="h-4 w-4" />
        </button>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        {/* Top K */}
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Label className="text-xs">Top K</Label>
              <InfoTooltip>
                Maximum chunks to retrieve for responses. More chunks = more context but slower.
              </InfoTooltip>
            </div>
            <span className="text-xs font-mono bg-background px-2 py-0.5 rounded">
              {config.kb_top_k}
            </span>
          </div>
          <Slider
            value={[config.kb_top_k]}
            onValueChange={([v]) => onConfigChange(config.id, 'kb_top_k', v)}
            min={1}
            max={20}
            step={1}
            disabled={disabled}
            className="cursor-pointer"
          />
          <div className="flex justify-between text-xs text-muted-foreground">
            <span>1</span>
            <span>20</span>
          </div>
        </div>

        {/* Similarity Threshold */}
        <div className="space-y-2">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Label className="text-xs">Similarity Threshold</Label>
              <InfoTooltip>
                Minimum similarity score. Higher = stricter but may miss relevant content.
              </InfoTooltip>
            </div>
            <span className="text-xs font-mono bg-background px-2 py-0.5 rounded">
              {config.kb_similarity_threshold.toFixed(2)}
            </span>
          </div>
          <Slider
            value={[config.kb_similarity_threshold]}
            onValueChange={([v]) => onConfigChange(config.id, 'kb_similarity_threshold', v)}
            min={0.1}
            max={1}
            step={0.05}
            disabled={disabled}
            className="cursor-pointer"
          />
          <div className="flex justify-between text-xs text-muted-foreground">
            <span>0.1 (broad)</span>
            <span>1.0 (strict)</span>
          </div>
        </div>
      </div>
    </div>
  );
});

// Loading state component
function LoadingState() {
  return (
    <div className="flex items-center justify-center py-8">
      <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      <span className="ml-2 text-sm text-muted-foreground">Loading Knowledge Bases...</span>
    </div>
  );
}

// Empty state component
function EmptyState() {
  return (
    <div className="text-center py-8 border-2 border-dashed rounded-lg">
      <Database className="h-8 w-8 text-muted-foreground mx-auto mb-2" />
      <p className="text-sm text-muted-foreground mb-2">No Knowledge Bases available</p>
      <p className="text-xs text-muted-foreground">
        Please create a Knowledge Base first to use this feature
      </p>
    </div>
  );
}

export const KnowledgeBaseSection = memo(function KnowledgeBaseSection({
  formData,
  onChange,
  disabled = false,
  availableKnowledgeBases,
}: KnowledgeBaseSectionProps & { availableKnowledgeBases: KnowledgeBaseOption[]; isLoading?: boolean }) {
  // Check if loading based on availableKnowledgeBases being undefined/null
  const isLoading = !availableKnowledgeBases;

  // Memoize selected IDs for O(1) lookup
  const selectedIds = useMemo(
    () => new Set(formData.knowledge_bases.map((k) => k.id)),
    [formData.knowledge_bases]
  );

  // Handler for toggling KB selection
  const handleToggle = useCallback(
    (id: number, checked: boolean) => {
      if (checked) {
        // Find KB name from available list
        const kb = availableKnowledgeBases?.find((k) => k.id === id);
        // Add KB with default settings
        const newKb: FlowKnowledgeBase = {
          id: formData.knowledge_bases.length + 1, // pivot id
          knowledge_base_id: id,
          name: kb?.name || `KB #${id}`,
          kb_top_k: 5,
          kb_similarity_threshold: 0.7,
        };
        onChange('knowledge_bases', [...formData.knowledge_bases, newKb]);
      } else {
        // Remove KB
        onChange(
          'knowledge_bases',
          formData.knowledge_bases.filter((k) => k.knowledge_base_id !== id)
        );
      }
    },
    [formData.knowledge_bases, availableKnowledgeBases, onChange]
  );

  // Handler for removing KB
  const handleRemove = useCallback(
    (id: number) => {
      onChange(
        'knowledge_bases',
        formData.knowledge_bases.filter((k) => k.id !== id)
      );
    },
    [formData.knowledge_bases, onChange]
  );

  // Handler for updating KB config
  const handleConfigChange = useCallback(
    (id: number, field: 'kb_top_k' | 'kb_similarity_threshold', value: number) => {
      onChange(
        'knowledge_bases',
        formData.knowledge_bases.map((kb) =>
          kb.id === id ? { ...kb, [field]: value } : kb
        )
      );
    },
    [formData.knowledge_bases, onChange]
  );

  // Get KB name by pivot ID
  const getKbName = useCallback(
    (kbConfig: FlowKnowledgeBase) => {
      return kbConfig.name || `KB #${kbConfig.knowledge_base_id}`;
    },
    []
  );

  return (
    <div className="border rounded-lg p-6 space-y-4">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <BookOpen className="h-5 w-5 text-muted-foreground" />
          <span className="font-medium">Knowledge Bases</span>
          <Badge variant="outline" className="text-xs">
            {formData.knowledge_bases.length} selected
          </Badge>
        </div>
        <InfoTooltip>
          Connect Knowledge Bases so AI can search and reference information when answering questions
        </InfoTooltip>
      </div>

      <p className="text-sm text-muted-foreground">
        Select Knowledge Bases that AI will use to search for relevant information
      </p>

      {/* KB Selection */}
      {isLoading ? (
        <LoadingState />
      ) : availableKnowledgeBases.length === 0 ? (
        <EmptyState />
      ) : (
        <div className="space-y-4">
          {/* Available KBs */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
            {availableKnowledgeBases.map((kb) => (
              <KnowledgeBaseItem
                key={kb.id}
                kb={kb}
                isSelected={selectedIds.has(kb.id)}
                onToggle={handleToggle}
                disabled={disabled}
              />
            ))}
          </div>

          {/* Per-KB Settings */}
          {formData.knowledge_bases.length > 0 && (
            <div className="space-y-3 pt-4 border-t">
              <div className="flex items-center gap-2">
                <Search className="h-4 w-4 text-muted-foreground" />
                <Label className="text-sm font-medium">Configure each Knowledge Base</Label>
              </div>
              <div className="space-y-3">
                {formData.knowledge_bases.map((kbConfig) => (
                  <KnowledgeBaseConfigItem
                    key={kbConfig.id}
                    config={kbConfig}
                    name={getKbName(kbConfig)}
                    onRemove={handleRemove}
                    onConfigChange={handleConfigChange}
                    disabled={disabled}
                  />
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      {/* Info box when KBs selected */}
      {formData.knowledge_bases.length > 0 && (
        <div className="bg-muted/50 rounded-lg p-3 text-sm">
          <p className="text-muted-foreground">
            AI will search {formData.knowledge_bases.length} Knowledge Base(s)
            and retrieve the most relevant chunks based on Top K and Similarity Threshold settings
          </p>
        </div>
      )}
    </div>
  );
});
