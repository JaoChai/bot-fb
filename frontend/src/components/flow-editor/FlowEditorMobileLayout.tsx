import type { ReactNode } from 'react';
import {
  Loader2,
  Save,
  List,
  MessageSquare,
  Settings,
  ArrowLeft,
  Plus,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { ChatEmulator } from '@/components/flows';
import type { CreateFlowData, Flow } from '@/types/api';
import type { StreamingMessage } from '@/hooks/useStreamingChat';

export type MobileTab = 'flows' | 'editor' | 'test';

interface FlowEditorMobileLayoutProps {
  // State
  mobileActiveTab: MobileTab;
  onMobileTabChange: (tab: MobileTab) => void;
  // Flow data
  formData: CreateFlowData;
  hasChanges: boolean;
  isSaving: boolean;
  // Bot/flow context
  botId: number;
  selectedFlowId: number | null;
  flows: Flow[];
  isLoadingFlows: boolean;
  isLoadingFlow: boolean;
  showEditor: boolean;
  isCreatingNew: boolean;
  // Editor tabs panel (pre-rendered to avoid re-passing all tab props)
  editorTabsPanel: ReactNode;
  // Save action
  onSave: () => void;
  // Chat
  chatMessages: StreamingMessage[];
  isStreaming: boolean;
  onSendChatMessage: (message: string) => Promise<void>;
  onCancelStream: () => void;
  onClearMessages: () => void;
  // Navigation
  onNavigate: (path: string) => void;
}

function MobileBottomTabs({
  activeTab,
  onTabChange,
}: {
  activeTab: MobileTab;
  onTabChange: (tab: MobileTab) => void;
}) {
  const items: Array<{ id: MobileTab; icon: typeof List; label: string }> = [
    { id: 'flows', icon: List, label: 'Flows' },
    { id: 'editor', icon: Settings, label: 'Editor' },
    { id: 'test', icon: MessageSquare, label: 'ทดสอบ' },
  ];
  return (
    <div className="fixed bottom-0 left-0 right-0 border-t bg-background md:hidden z-50 pb-safe">
      <div className="grid grid-cols-3 h-14">
        {items.map(({ id, icon: Icon, label }) => (
          <button
            key={id}
            onClick={() => onTabChange(id)}
            className={cn(
              'flex flex-col items-center justify-center gap-0.5 transition-colors',
              activeTab === id ? 'text-foreground bg-muted' : 'text-muted-foreground hover:text-foreground'
            )}
          >
            <Icon className="h-5 w-5" strokeWidth={1.5} />
            <span className="text-xs">{label}</span>
          </button>
        ))}
      </div>
    </div>
  );
}

export function FlowEditorMobileLayout({
  mobileActiveTab,
  onMobileTabChange,
  formData,
  hasChanges,
  isSaving,
  botId,
  selectedFlowId,
  flows,
  isLoadingFlows,
  isLoadingFlow,
  showEditor,
  isCreatingNew,
  editorTabsPanel,
  onSave,
  chatMessages,
  isStreaming,
  onSendChatMessage,
  onCancelStream,
  onClearMessages,
  onNavigate,
}: FlowEditorMobileLayoutProps) {
  return (
    <div className="flex flex-col flex-1 md:hidden pb-14">
      <div className="flex items-center justify-between px-4 py-3 border-b bg-background">
        <div className="flex items-center gap-2 min-w-0 flex-1">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => onNavigate('/bots')}
            aria-label="กลับไปหน้าการเชื่อมต่อ"
            className="-ml-2 h-9 w-9 shrink-0"
          >
            <ArrowLeft className="h-4 w-4" strokeWidth={1.5} />
          </Button>
          <span className="font-semibold truncate">
            {formData.name || 'Flow ใหม่'}
          </span>
          {formData.is_default && (
            <Badge variant="secondary" className="text-[10px] shrink-0">Base</Badge>
          )}
        </div>
        <div className="flex items-center gap-2 shrink-0">
          {hasChanges && !isSaving && (
            <span className="inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400 tabular-nums">
              <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
              ยังไม่บันทึก
            </span>
          )}
          <Button size="sm" onClick={onSave} disabled={isSaving || !hasChanges}>
            {isSaving ? (
              <Loader2 className="h-4 w-4" strokeWidth={1.5} />
            ) : (
              <>
                <Save className="h-4 w-4 mr-1" strokeWidth={1.5} />
                บันทึก
              </>
            )}
          </Button>
        </div>
      </div>

      <div className="flex-1 overflow-hidden">
        {mobileActiveTab === 'flows' && (
          <div className="h-full overflow-y-auto p-4 space-y-2">
            <Button
              variant="outline"
              className="w-full"
              onClick={() => onNavigate(`/flows/new?botId=${botId}`)}
            >
              <Plus className="h-4 w-4 mr-2" strokeWidth={1.5} />สร้าง Flow ใหม่
            </Button>
            {isLoadingFlows ? (
              <div className="flex justify-center py-8">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
              </div>
            ) : flows.length === 0 ? (
              <p className="text-sm text-muted-foreground text-center py-8">ยังไม่มี Flow</p>
            ) : (
              <div className="space-y-1">
                {[...flows]
                  .sort((a, b) => (a.is_default === b.is_default ? 0 : a.is_default ? -1 : 1))
                  .map((flow) => (
                    <button
                      key={flow.id}
                      onClick={() => {
                        onNavigate(`/flows/${flow.id}/edit?botId=${botId}`);
                        onMobileTabChange('editor');
                      }}
                      className={cn(
                        'w-full text-left px-3 py-2.5 rounded-md text-sm transition-colors',
                        selectedFlowId === flow.id
                          ? 'bg-muted text-foreground font-medium'
                          : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
                      )}
                    >
                      <div className="flex items-center gap-2">
                        <span className="truncate">{flow.name}</span>
                        {flow.is_default && (
                          <Badge variant="secondary" className="text-[10px] ml-auto">Base</Badge>
                        )}
                      </div>
                    </button>
                  ))}
              </div>
            )}
          </div>
        )}

        {mobileActiveTab === 'editor' && (
          <div className="h-full overflow-y-auto">
            {!showEditor ? (
              <div className="flex-1 flex flex-col items-center justify-center h-full p-8">
                <p className="text-muted-foreground text-center mb-4">เลือกหรือสร้าง Flow เพื่อเริ่มต้น</p>
                <Button onClick={() => onMobileTabChange('flows')}>
                  <List className="h-4 w-4 mr-2" strokeWidth={1.5} />ดู Flows ทั้งหมด
                </Button>
              </div>
            ) : isLoadingFlow ? (
              <div className="flex items-center justify-center h-full">
                <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              </div>
            ) : (
              <div className="px-4 py-4 space-y-6">
                {editorTabsPanel}
              </div>
            )}
          </div>
        )}

        {mobileActiveTab === 'test' && (
          <div className="h-full flex flex-col">
            <ChatEmulator
              messages={chatMessages}
              isStreaming={isStreaming}
              onSendMessage={onSendChatMessage}
              onCancelStream={onCancelStream}
              onClearMessages={onClearMessages}
              disabled={!selectedFlowId}
              disabledReason={
                isCreatingNew
                  ? 'สร้างและบันทึก Flow ก่อน แล้วกลับมาทดสอบ'
                  : !selectedFlowId
                  ? 'บันทึก Flow ก่อนทดสอบ'
                  : undefined
              }
              className="flex-1 w-full border-0"
            />
          </div>
        )}
      </div>

      <MobileBottomTabs activeTab={mobileActiveTab} onTabChange={onMobileTabChange} />
    </div>
  );
}
