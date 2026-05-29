import { FileText, BookOpen, Cpu, Puzzle } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PromptTab } from './tabs/PromptTab';
import { KnowledgeTab } from './tabs/KnowledgeTab';
import { ModelTab } from './tabs/ModelTab';
import { PluginsTab } from './tabs/PluginsTab';
import type { KnowledgeBaseListItem } from '@/hooks/useKnowledgeBase';
import type { CreateFlowData, CreateFlowKnowledgeBaseData } from '@/types/api';

const EDITOR_TABS = [
  { value: 'prompt', label: 'Prompt', icon: FileText },
  { value: 'knowledge', label: 'Knowledge', icon: BookOpen },
  { value: 'model', label: 'Model', icon: Cpu },
  { value: 'plugins', label: 'การแจ้งเตือน', icon: Puzzle },
] as const;

export type EditorTab = (typeof EDITOR_TABS)[number]['value'];

interface FlowEditorTabsPanelProps {
  activeTab: EditorTab;
  onTabChange: (tab: EditorTab) => void;
  formData: CreateFlowData;
  onFieldChange: (field: string, value: unknown) => void;
  onKnowledgeBasesChange: (kbs: CreateFlowKnowledgeBaseData[]) => void;
  allKnowledgeBases: KnowledgeBaseListItem[];
  isLoadingKBs: boolean;
  botId: number;
  selectedFlowId: number | null;
}

export function FlowEditorTabsPanel({
  activeTab,
  onTabChange,
  formData,
  onFieldChange,
  onKnowledgeBasesChange,
  allKnowledgeBases,
  isLoadingKBs,
  botId,
  selectedFlowId,
}: FlowEditorTabsPanelProps) {
  return (
    <div className="grid gap-6 md:grid-cols-[200px_1fr] md:gap-8">
      <aside className="md:border-r md:pr-6">
        <nav className="flex md:flex-col gap-1 overflow-x-auto md:overflow-visible -mx-1 px-1">
          {EDITOR_TABS.map((t) => {
            const Icon = t.icon;
            const isActive = activeTab === t.value;
            return (
              <button
                key={t.value}
                type="button"
                onClick={() => onTabChange(t.value as EditorTab)}
                className={cn(
                  'relative flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors text-left shrink-0',
                  'before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-4 before:w-0.5 before:rounded-full before:bg-primary before:transition-opacity',
                  isActive
                    ? 'bg-accent text-foreground before:opacity-100'
                    : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground before:opacity-0',
                )}
              >
                <Icon className="size-4 shrink-0" strokeWidth={1.5} />
                <span>{t.label}</span>
              </button>
            );
          })}
        </nav>
      </aside>

      <div className="min-w-0 space-y-6">
        {activeTab === 'prompt' && (
          <PromptTab
            name={formData.name}
            systemPrompt={formData.system_prompt}
            isDefault={formData.is_default ?? false}
            onChange={onFieldChange}
          />
        )}
        {activeTab === 'knowledge' && (
          <KnowledgeTab
            allKnowledgeBases={allKnowledgeBases}
            selectedKnowledgeBases={formData.knowledge_bases || []}
            isLoading={isLoadingKBs}
            onChange={onKnowledgeBasesChange}
          />
        )}
        {activeTab === 'model' && (
          <ModelTab
            temperature={formData.temperature ?? 0.7}
            maxTokens={formData.max_tokens ?? 2048}
            onChange={onFieldChange}
          />
        )}
        {activeTab === 'plugins' && (
          <PluginsTab botId={botId} flowId={selectedFlowId} />
        )}
      </div>
    </div>
  );
}
