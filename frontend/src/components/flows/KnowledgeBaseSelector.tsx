import { useState } from 'react';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Loader2, ChevronDown, Trash2, BookOpen, Plus, FileText, Layers } from 'lucide-react';
import { EmptyState } from '@/components/common';
import { cn } from '@/lib/utils';
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

export function KnowledgeBaseSelector({
  allKnowledgeBases,
  selectedKnowledgeBases,
  isLoading,
  onChange,
}: KnowledgeBaseSelectorProps) {
  const [openIds, setOpenIds] = useState<Set<number>>(new Set());

  const selectedIds = new Set(selectedKnowledgeBases.map((kb) => kb.id));
  const unselected = allKnowledgeBases.filter((kb) => !selectedIds.has(kb.id));

  const handleAdd = (id: number) => {
    onChange([
      ...selectedKnowledgeBases,
      { id, kb_top_k: 5, kb_similarity_threshold: 0.7 },
    ]);
    setOpenIds((prev) => new Set(prev).add(id));
  };

  const handleRemove = (id: number) => {
    onChange(selectedKnowledgeBases.filter((kb) => kb.id !== id));
    setOpenIds((prev) => {
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
  };

  const handleUpdate = (
    id: number,
    field: 'kb_top_k' | 'kb_similarity_threshold',
    value: number,
  ) => {
    onChange(
      selectedKnowledgeBases.map((kb) => (kb.id === id ? { ...kb, [field]: value } : kb)),
    );
  };

  const toggleOpen = (id: number) => {
    setOpenIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-8">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-3">
      {/* Add-KB control */}
      {unselected.length > 0 && (
        <div className="flex items-center gap-2">
          <Select onValueChange={(v) => handleAdd(Number(v))}>
            <SelectTrigger className="max-w-md">
              <div className="flex items-center gap-2">
                <Plus className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
                <SelectValue placeholder="เพิ่มฐานความรู้..." />
              </div>
            </SelectTrigger>
            <SelectContent>
              {unselected.map((kb) => (
                <SelectItem key={kb.id} value={String(kb.id)}>
                  {kb.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}

      {/* No KBs exist at all */}
      {allKnowledgeBases.length === 0 && (
        <EmptyState
          icon={BookOpen}
          title="ยังไม่มี Knowledge Base"
          description="สร้างฐานความรู้ที่หน้า Knowledge Base ก่อน"
          size="sm"
        />
      )}

      {/* Empty state when none selected but KBs exist */}
      {allKnowledgeBases.length > 0 && selectedKnowledgeBases.length === 0 && (
        <EmptyState
          icon={BookOpen}
          title="ยังไม่ได้เลือก Knowledge Base"
          description="เลือกฐานความรู้จากด้านบนเพื่อให้บอทใช้ตอบลูกค้า"
          size="sm"
        />
      )}

      {/* Selected KBs — accordion list */}
      {selectedKnowledgeBases.length > 0 && (
        <div className="space-y-2">
          {selectedKnowledgeBases.map((sel) => {
            const meta = allKnowledgeBases.find((k) => k.id === sel.id);
            if (!meta) return null;
            const isOpen = openIds.has(sel.id);
            const topK = sel.kb_top_k ?? 5;
            const threshold = sel.kb_similarity_threshold ?? 0.7;
            return (
              <Collapsible
                key={sel.id}
                open={isOpen}
                onOpenChange={() => toggleOpen(sel.id)}
                className="rounded-md border bg-card"
              >
                <div className="flex items-center gap-2 px-3 py-2">
                  <CollapsibleTrigger className="flex flex-1 items-center gap-3 min-w-0 text-left">
                    <ChevronDown
                      className={cn(
                        'h-4 w-4 text-muted-foreground shrink-0 transition-transform',
                        isOpen && 'rotate-180',
                      )}
                      strokeWidth={1.5}
                    />
                    <BookOpen
                      className="h-4 w-4 text-muted-foreground shrink-0"
                      strokeWidth={1.5}
                    />
                    <div className="min-w-0">
                      <p className="text-sm font-medium truncate">{meta.name}</p>
                      {meta.description && (
                        <p className="text-xs text-muted-foreground truncate">
                          {meta.description}
                        </p>
                      )}
                    </div>
                    <div className="flex items-center gap-1.5 ml-auto mr-2 shrink-0">
                      <Badge variant="secondary" className="text-[10px] tabular-nums">
                        <FileText className="h-3 w-3 mr-0.5" strokeWidth={1.5} />
                        {meta.document_count}
                      </Badge>
                      <Badge variant="outline" className="text-[10px] tabular-nums">
                        <Layers className="h-3 w-3 mr-0.5" strokeWidth={1.5} />
                        {meta.chunk_count}
                      </Badge>
                    </div>
                  </CollapsibleTrigger>
                  <Button
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 text-muted-foreground hover:text-destructive"
                    onClick={() => handleRemove(sel.id)}
                    aria-label="ลบฐานความรู้นี้"
                  >
                    <Trash2 className="h-3.5 w-3.5" strokeWidth={1.5} />
                  </Button>
                </div>
                <CollapsibleContent>
                  <div className="border-t px-3 py-3 space-y-4">
                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <Label className="text-xs">
                          Top K{' '}
                          <span className="text-muted-foreground font-normal">
                            (จำนวน chunks ที่ดึงมาใช้)
                          </span>
                        </Label>
                        <span className="text-xs font-mono tabular-nums">{topK}</span>
                      </div>
                      <Slider
                        value={[topK]}
                        min={1}
                        max={20}
                        step={1}
                        onValueChange={(v) => handleUpdate(sel.id, 'kb_top_k', v[0])}
                      />
                    </div>
                    <div className="space-y-2">
                      <div className="flex items-center justify-between">
                        <Label className="text-xs">
                          Similarity Threshold{' '}
                          <span className="text-muted-foreground font-normal">
                            (ต่ำสุดของความคล้าย)
                          </span>
                        </Label>
                        <span className="text-xs font-mono tabular-nums">
                          {threshold.toFixed(2)}
                        </span>
                      </div>
                      <Slider
                        value={[threshold]}
                        min={0.1}
                        max={1}
                        step={0.05}
                        onValueChange={(v) => handleUpdate(sel.id, 'kb_similarity_threshold', v[0])}
                      />
                    </div>
                  </div>
                </CollapsibleContent>
              </Collapsible>
            );
          })}
        </div>
      )}
    </div>
  );
}
