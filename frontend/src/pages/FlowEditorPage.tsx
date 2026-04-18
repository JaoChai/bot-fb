import { useState, useEffect, useCallback } from 'react';
import { useParams, useSearchParams, useNavigate, useLocation } from 'react-router';
import {
  Loader2,
  Save,
  List,
  MessageSquare,
  PanelRightClose,
  PanelRightOpen,
  Settings,
  ArrowLeft,
  Plus,
  FileText,
  BookOpen,
  Cpu,
  Puzzle,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useFlow, useCreateFlow, useUpdateFlow, useFlowOperations } from '@/hooks/useFlows';
import { useStreamingChat } from '@/hooks/useStreamingChat';
import { useAllKnowledgeBases } from '@/hooks/useKnowledgeBase';
import { useToast } from '@/hooks/use-toast';
import {
  FlowsList,
  ChatEmulator,
} from '@/components/flows';
import {
  PromptTab,
  KnowledgeTab,
  ModelTab,
  PluginsTab,
} from '@/components/flow-editor';
import type { CreateFlowData, CreateFlowKnowledgeBaseData } from '@/types/api';

type MobileTab = 'flows' | 'editor' | 'test';
type EditorTab = 'prompt' | 'knowledge' | 'model' | 'plugins';

const EDITOR_TABS = [
  { value: 'prompt', label: 'Prompt', icon: FileText },
  { value: 'knowledge', label: 'Knowledge', icon: BookOpen },
  { value: 'model', label: 'Model', icon: Cpu },
  { value: 'plugins', label: 'การแจ้งเตือน', icon: Puzzle },
] as const;

const DEFAULT_SYSTEM_PROMPT = `คุณคือผู้ช่วย AI ที่เป็นมิตรและช่วยเหลือลูกค้าอย่างมืออาชีพ

## บทบาทของคุณ:
- ตอบคำถามอย่างชัดเจน กระชับ และเป็นมิตร
- ให้ข้อมูลที่ถูกต้องและเป็นประโยชน์
- หากไม่ทราบคำตอบ ให้ยอมรับตรงๆ และแนะนำวิธีหาข้อมูลเพิ่มเติม

## แนวทางการสื่อสาร:
- ใช้ภาษาที่สุภาพและเข้าใจง่าย
- ตอบในภาษาเดียวกับที่ลูกค้าใช้
- ถามคำถามเพื่อทำความเข้าใจหากข้อมูลไม่ชัดเจน`;

const INITIAL_FORM_DATA: CreateFlowData = {
  name: '',
  description: '',
  system_prompt: DEFAULT_SYSTEM_PROMPT,
  temperature: 0.7,
  max_tokens: 2048,
  knowledge_bases: [],
  is_default: false,
};

function mapFlowToFormData(flow: NonNullable<ReturnType<typeof useFlow>['data']>): CreateFlowData {
  const kbData: CreateFlowKnowledgeBaseData[] = flow.knowledge_bases?.map(kb => ({
    id: kb.id,
    kb_top_k: kb.kb_top_k,
    kb_similarity_threshold: kb.kb_similarity_threshold,
  })) ?? [];
  return {
    name: flow.name,
    description: flow.description || '',
    system_prompt: flow.system_prompt,
    temperature: flow.temperature,
    max_tokens: flow.max_tokens,
    knowledge_bases: kbData,
    is_default: flow.is_default,
  };
}

function MobileBottomTabs({ activeTab, onTabChange }: { activeTab: MobileTab; onTabChange: (tab: MobileTab) => void }) {
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

export function FlowEditorPage() {
  const { flowId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { toast } = useToast();

  const botIdParam = searchParams.get('botId') || searchParams.get('botid');
  const parsedBotId = botIdParam ? parseInt(botIdParam, 10) : null;
  const botId = parsedBotId && !isNaN(parsedBotId) ? parsedBotId : null;

  const isEditorEntryMode = location.pathname === '/flows/editor';

  const { flows, isLoading: isLoadingFlows, isSuccess: isFlowsSuccess } = useFlowOperations(botId);

  const parsedFlowId = flowId && flowId !== 'new' ? parseInt(flowId, 10) : null;
  const selectedFlowId = parsedFlowId && !isNaN(parsedFlowId) ? parsedFlowId : null;
  const isCreatingNew = flowId === 'new' || location.pathname === '/flows/new';

  const { data: existingFlow, isLoading: isLoadingFlow } = useFlow(botId, selectedFlowId);
  const { data: allKnowledgeBases = [], isLoading: isLoadingKBs } = useAllKnowledgeBases();

  const createMutation = useCreateFlow(botId);
  const updateMutation = useUpdateFlow(botId, selectedFlowId);

  const [formData, setFormData] = useState<CreateFlowData>(INITIAL_FORM_DATA);
  const [hasChanges, setHasChanges] = useState(false);
  const [activeEditorTab, setActiveEditorTab] = useState<EditorTab>('prompt');
  const [mobileActiveTab, setMobileActiveTab] = useState<MobileTab>('editor');
  const [chatOpen, setChatOpen] = useState(true);

  const {
    messages: chatMessages,
    isStreaming,
    sendMessage: sendStreamingMessage,
    cancelStream,
    clearMessages,
  } = useStreamingChat({
    botId,
    flowId: selectedFlowId,
  });

  useEffect(() => {
    if (isEditorEntryMode && isFlowsSuccess && botId) {
      if (flows.length > 0) {
        const defaultFlow = flows.find(f => f.is_default);
        const flowToLoad = defaultFlow || flows[0];
        navigate(`/flows/${flowToLoad.id}/edit?botId=${botId}`, { replace: true });
      } else {
        navigate(`/flows/new?botId=${botId}`, { replace: true });
      }
    }
  }, [isEditorEntryMode, isFlowsSuccess, flows, botId, navigate]);

  useEffect(() => {
    if (existingFlow) {
      setFormData(mapFlowToFormData(existingFlow));
      setHasChanges(false);
    }
  }, [existingFlow]);

  const handleChange = useCallback(<K extends keyof CreateFlowData>(field: K, value: CreateFlowData[K]) => {
    setFormData(prev => ({ ...prev, [field]: value }));
    setHasChanges(true);
  }, []);

  const handleFieldChange = useCallback((field: string, value: unknown) => {
    handleChange(field as keyof CreateFlowData, value as CreateFlowData[keyof CreateFlowData]);
  }, [handleChange]);

  const handleKnowledgeBasesChange = useCallback((kbs: CreateFlowKnowledgeBaseData[]) => {
    handleChange('knowledge_bases', kbs);
  }, [handleChange]);

  const handleSendChatMessage = useCallback(async (message: string) => {
    await sendStreamingMessage(message);
  }, [sendStreamingMessage]);

  const handleSave = async () => {
    if (!botId) {
      toast({ title: 'ผิดพลาด', description: 'ไม่พบ Bot ID', variant: 'destructive' });
      return;
    }
    if (!formData.name.trim()) {
      toast({ title: 'กรุณากรอกชื่อ Flow', variant: 'destructive' });
      return;
    }
    if (!formData.system_prompt.trim()) {
      toast({ title: 'กรุณากรอก System Prompt', variant: 'destructive' });
      return;
    }

    const dataToSave = { ...formData };

    try {
      if (selectedFlowId) {
        await updateMutation.mutateAsync(dataToSave);
        toast({ title: 'บันทึกแล้ว', description: 'การเปลี่ยนแปลงถูกบันทึกเรียบร้อย' });
      } else {
        const newFlow = await createMutation.mutateAsync(dataToSave);
        toast({ title: 'สร้างแล้ว', description: 'Flow ใหม่ถูกสร้างเรียบร้อย' });
        if (newFlow?.id) {
          navigate(`/flows/${newFlow.id}/edit?botId=${botId}`);
        }
      }
      setHasChanges(false);
    } catch (err) {
      toast({
        title: 'ผิดพลาด',
        description: err instanceof Error ? err.message : 'ไม่สามารถบันทึกได้',
        variant: 'destructive',
      });
    }
  };

  const handleDiscard = () => {
    if (existingFlow) {
      setFormData(mapFlowToFormData(existingFlow));
    } else {
      setFormData(INITIAL_FORM_DATA);
    }
    setHasChanges(false);
  };

  if (isEditorEntryMode && (isLoadingFlows || flows.length > 0)) {
    return (
      <div className="flex items-center justify-center h-screen bg-background">
        <div className="text-center">
          <Loader2 className="h-8 w-8 animate-spin mx-auto mb-4 text-muted-foreground" />
          <p className="text-muted-foreground">กำลังโหลด Flow Editor...</p>
        </div>
      </div>
    );
  }

  if (!botId) {
    return (
      <div className="flex items-center justify-center h-screen bg-background">
        <div className="text-center">
          <p className="text-destructive mb-4">ไม่ได้เลือก Bot กรุณาเลือก Bot ก่อน</p>
          <Button onClick={() => navigate('/bots')}>ไปหน้า Bot</Button>
        </div>
      </div>
    );
  }

  const isSaving = createMutation.isPending || updateMutation.isPending;
  const showEditor = selectedFlowId || isCreatingNew;

  const editorTabs = (
    <div className="grid gap-6 md:grid-cols-[200px_1fr] md:gap-8">
      <aside className="md:border-r md:pr-6">
        <nav className="flex md:flex-col gap-1 overflow-x-auto md:overflow-visible -mx-1 px-1">
          {EDITOR_TABS.map((t) => {
            const Icon = t.icon;
            const isActive = activeEditorTab === t.value;
            return (
              <button
                key={t.value}
                type="button"
                onClick={() => setActiveEditorTab(t.value as EditorTab)}
                className={cn(
                  'relative flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors text-left shrink-0',
                  'before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-4 before:w-0.5 before:rounded-full before:bg-primary before:transition-opacity',
                  isActive
                    ? 'bg-accent text-foreground before:opacity-100'
                    : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground before:opacity-0',
                )}
              >
                <Icon className="h-4 w-4 shrink-0" strokeWidth={1.5} />
                <span>{t.label}</span>
              </button>
            );
          })}
        </nav>
      </aside>

      <div className="min-w-0 space-y-6">
        {activeEditorTab === 'prompt' && (
          <PromptTab
            name={formData.name}
            systemPrompt={formData.system_prompt}
            isDefault={formData.is_default ?? false}
            onChange={handleFieldChange}
          />
        )}
        {activeEditorTab === 'knowledge' && (
          <KnowledgeTab
            allKnowledgeBases={allKnowledgeBases}
            selectedKnowledgeBases={formData.knowledge_bases || []}
            isLoading={isLoadingKBs}
            onChange={handleKnowledgeBasesChange}
          />
        )}
        {activeEditorTab === 'model' && (
          <ModelTab
            temperature={formData.temperature ?? 0.7}
            maxTokens={formData.max_tokens ?? 2048}
            onChange={handleFieldChange}
          />
        )}
        {activeEditorTab === 'plugins' && (
          <PluginsTab botId={botId} flowId={selectedFlowId} />
        )}
      </div>
    </div>
  );

  const stickyActionBar = (
    <div className="sticky bottom-0 -mx-4 md:-mx-6 mt-6 border-t bg-background/95 px-4 md:px-6 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80 pb-safe z-10">
      <div className="flex items-center justify-between gap-3">
        <span className="text-sm text-muted-foreground hidden sm:block">
          {hasChanges ? 'มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก' : 'การเปลี่ยนแปลงจะมีผลทันที'}
        </span>
        <div className="flex items-center gap-2 ml-auto">
          {hasChanges && (
            <Button variant="ghost" size="sm" onClick={handleDiscard}>
              ยกเลิก
            </Button>
          )}
          <Button onClick={handleSave} disabled={isSaving || !hasChanges} className="min-w-[100px]">
            {isSaving ? (
              <><Loader2 className="h-4 w-4 mr-2 animate-spin" strokeWidth={1.5} />บันทึก...</>
            ) : (
              <><Save className="h-4 w-4 mr-2" strokeWidth={1.5} />บันทึก</>
            )}
          </Button>
        </div>
      </div>
    </div>
  );

  return (
    <div className="flex h-screen bg-background overflow-hidden">
      {/* Desktop Sidebar */}
      <div className="hidden md:block">
        <FlowsList
          flows={flows}
          isLoading={isLoadingFlows}
          selectedFlowId={selectedFlowId}
          botId={botId}
        />
      </div>

      {/* Desktop Main + Chat */}
      <div className="hidden md:flex flex-1 overflow-hidden">
        <div className="flex-1 flex flex-col overflow-hidden">
          {!showEditor ? (
            <div className="flex-1 flex items-center justify-center">
              <div className="text-center space-y-3">
                <p className="text-lg text-muted-foreground">เลือกหรือสร้าง Flow เพื่อเริ่มต้น</p>
                <Button variant="outline" onClick={() => navigate(`/flows/new?botId=${botId}`)}>
                  <Plus className="h-4 w-4 mr-2" strokeWidth={1.5} />สร้าง Flow ใหม่
                </Button>
              </div>
            </div>
          ) : isLoadingFlow ? (
            <div className="flex-1 flex items-center justify-center">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <div className="flex-1 overflow-y-auto">
              <div className="w-full px-6 py-6 space-y-6">
                <div className="flex items-center justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h1 className="text-xl sm:text-2xl font-semibold tracking-tight text-foreground truncate">
                        {formData.name || 'Flow ใหม่'}
                      </h1>
                      {formData.is_default && (
                        <Badge variant="secondary" className="text-[10px]">Base Flow</Badge>
                      )}
                      {hasChanges && (
                        <Badge variant="outline" className="text-[10px] gap-1.5 shrink-0">
                          <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                          มีการเปลี่ยนแปลง
                        </Badge>
                      )}
                    </div>
                    <p className="text-sm text-muted-foreground mt-1">
                      กำหนดค่า prompt, model, agent และเครื่องมือสำหรับ Flow นี้
                    </p>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => setChatOpen(!chatOpen)}
                    aria-label={chatOpen ? 'ซ่อนแชททดสอบ' : 'แสดงแชททดสอบ'}
                    className="h-9 w-9 shrink-0"
                  >
                    {chatOpen ? <PanelRightClose className="h-4 w-4" strokeWidth={1.5} /> : <PanelRightOpen className="h-4 w-4" strokeWidth={1.5} />}
                  </Button>
                </div>

                {editorTabs}
                {stickyActionBar}
              </div>
            </div>
          )}
        </div>

        {chatOpen && (
          <ChatEmulator
            messages={chatMessages}
            isStreaming={isStreaming}
            onSendMessage={handleSendChatMessage}
            onCancelStream={cancelStream}
            onClearMessages={clearMessages}
            disabled={!selectedFlowId}
            disabledReason={
              isCreatingNew
                ? 'สร้างและบันทึก Flow ก่อน แล้วกลับมาทดสอบ'
                : !selectedFlowId
                ? 'บันทึก Flow ก่อนทดสอบ'
                : undefined
            }
          />
        )}
      </div>

      {/* Mobile */}
      <div className="flex flex-col flex-1 md:hidden pb-14">
        <div className="flex items-center justify-between px-4 py-3 border-b bg-background">
          <div className="flex items-center gap-2 min-w-0 flex-1">
            <Button
              variant="ghost"
              size="icon"
              onClick={() => navigate('/bots')}
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
            <Button size="sm" onClick={handleSave} disabled={isSaving || !hasChanges}>
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
                onClick={() => navigate(`/flows/new?botId=${botId}`)}
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
                          navigate(`/flows/${flow.id}/edit?botId=${botId}`);
                          setMobileActiveTab('editor');
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
                  <Button onClick={() => setMobileActiveTab('flows')}>
                    <List className="h-4 w-4 mr-2" strokeWidth={1.5} />ดู Flows ทั้งหมด
                  </Button>
                </div>
              ) : isLoadingFlow ? (
                <div className="flex items-center justify-center h-full">
                  <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                </div>
              ) : (
                <div className="px-4 py-4 space-y-6">
                  {editorTabs}
                </div>
              )}
            </div>
          )}

          {mobileActiveTab === 'test' && (
            <div className="h-full flex flex-col">
              <ChatEmulator
                messages={chatMessages}
                isStreaming={isStreaming}
                onSendMessage={handleSendChatMessage}
                onCancelStream={cancelStream}
                onClearMessages={clearMessages}
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

        <MobileBottomTabs activeTab={mobileActiveTab} onTabChange={setMobileActiveTab} />
      </div>
    </div>
  );
}

export default FlowEditorPage;
