import { memo, useMemo, useCallback } from 'react';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Badge } from '@/components/ui/badge';
import { BookOpen, X, Loader2 } from 'lucide-react';
import type { KnowledgeBaseListItem } from '@/hooks/useKnowledgeBase';

export interface KnowledgeBaseConfig {
  id: number;
  kb_top_k?: number;
  kb_similarity_threshold?: number;
}

interface KnowledgeBaseSelectorProps {
  allKnowledgeBases: KnowledgeBaseListItem[];
  selectedKnowledgeBases: KnowledgeBaseConfig[];
  isLoading: boolean;
  onChange: (knowledgeBases: KnowledgeBaseConfig[]) => void;
}

// Individual KB selection item (memoized)
const KnowledgeBaseItem = memo(function KnowledgeBaseItem({
  kb,
  isSelected,
  onToggle,
}: {
  kb: KnowledgeBaseListItem;
  isSelected: boolean;
  onToggle: (id: number, checked: boolean) => void;
}) {
  return (
    <label
      className={`flex items-center gap-3 p-2 rounded-lg cursor-pointer transition-colors ${
        isSelected ? 'bg-warning/10 border border-warning/30' : 'hover:bg-muted'
      }`}
    >
      <input
        type="checkbox"
        checked={isSelected}
        onChange={(e) => onToggle(kb.id, e.target.checked)}
        className="rounded border-border"
      />
      <div className="flex-1 min-w-0">
        <div className="font-medium text-sm truncate">{kb.name}</div>
        <div className="text-xs text-muted-foreground">
          {kb.document_count} เอกสาร • {kb.chunk_count} chunks
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
}: {
  config: KnowledgeBaseConfig;
  name: string;
  onRemove: (id: number) => void;
  onConfigChange: (id: number, field: 'kb_top_k' | 'kb_similarity_threshold', value: number) => void;
}) {
  return (
    <div className="border rounded-lg p-3 space-y-3">
      <div className="flex items-center justify-between">
        <span className="text-sm font-medium">{name}</span>
        <button
          onClick={() => onRemove(config.id)}
          className="text-muted-foreground hover:text-destructive"
        >
          <X className="h-4 w-4" />
        </button>
      </div>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <Label className="text-xs">Top K: {config.kb_top_k || 5}</Label>
          <Slider
            value={[config.kb_top_k || 5]}
            onValueChange={([v]) => onConfigChange(config.id, 'kb_top_k', v)}
            min={1}
            max={20}
            step={1}
            className="mt-2"
          />
        </div>
        <div>
          <Label className="text-xs">Threshold: {config.kb_similarity_threshold || 0.7}</Label>
          <Slider
            value={[config.kb_similarity_threshold || 0.7]}
            onValueChange={([v]) => onConfigChange(config.id, 'kb_similarity_threshold', v)}
            min={0.1}
            max={1}
            step={0.05}
            className="mt-2"
          />
        </div>
      </div>
    </div>
  );
});

export const KnowledgeBaseSelector = memo(function KnowledgeBaseSelector({
  allKnowledgeBases,
  selectedKnowledgeBases,
  isLoading,
  onChange,
}: KnowledgeBaseSelectorProps) {
  // Memoize selected IDs for O(1) lookup
  const selectedIds = useMemo(
    () => new Set(selectedKnowledgeBases.map((k) => k.id)),
    [selectedKnowledgeBases]
  );

  // Handler for toggling KB selection
  const handleToggle = useCallback(
    (id: number, checked: boolean) => {
      if (checked) {
        // Add KB with default settings
        onChange([
          ...selectedKnowledgeBases,
          { id, kb_top_k: 5, kb_similarity_threshold: 0.7 },
        ]);
      } else {
        // Remove KB
        onChange(selectedKnowledgeBases.filter((k) => k.id !== id));
      }
    },
    [selectedKnowledgeBases, onChange]
  );

  // Handler for removing KB
  const handleRemove = useCallback(
    (id: number) => {
      onChange(selectedKnowledgeBases.filter((k) => k.id !== id));
    },
    [selectedKnowledgeBases, onChange]
  );

  // Handler for updating KB config
  const handleConfigChange = useCallback(
    (id: number, field: 'kb_top_k' | 'kb_similarity_threshold', value: number) => {
      onChange(
        selectedKnowledgeBases.map((kb) =>
          kb.id === id ? { ...kb, [field]: value } : kb
        )
      );
    },
    [selectedKnowledgeBases, onChange]
  );

  // Get KB name by ID
  const getKbName = useCallback(
    (id: number) => {
      const kb = allKnowledgeBases.find((k) => k.id === id);
      return kb?.name || `KB #${id}`;
    },
    [allKnowledgeBases]
  );

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2">
        <BookOpen className="h-4 w-4 text-muted-foreground" />
        <span className="text-sm font-medium">ฐานความรู้ที่เชื่อมต่อ</span>
        <Badge variant="outline" className="text-xs">
          {selectedKnowledgeBases.length} เลือก
        </Badge>
      </div>

      {/* KB Selection Dropdown */}
      <div className="border rounded-lg p-3 space-y-2 max-h-48 overflow-y-auto">
        {isLoading ? (
          <div className="flex items-center justify-center py-4">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
          </div>
        ) : allKnowledgeBases.length === 0 ? (
          <p className="text-sm text-muted-foreground text-center py-4">
            ยังไม่มีฐานความรู้ กรุณาสร้างฐานความรู้ก่อน
          </p>
        ) : (
          allKnowledgeBases.map((kb) => (
            <KnowledgeBaseItem
              key={kb.id}
              kb={kb}
              isSelected={selectedIds.has(kb.id)}
              onToggle={handleToggle}
            />
          ))
        )}
      </div>

      {/* Per-KB Settings */}
      {selectedKnowledgeBases.length > 0 && (
        <div className="space-y-4 mt-4">
          <Label className="text-xs text-muted-foreground">
            ตั้งค่าแต่ละฐานความรู้
          </Label>
          {selectedKnowledgeBases.map((kbConfig) => (
            <KnowledgeBaseConfigItem
              key={kbConfig.id}
              config={kbConfig}
              name={getKbName(kbConfig.id)}
              onRemove={handleRemove}
              onConfigChange={handleConfigChange}
            />
          ))}
        </div>
      )}
    </div>
  );
});
