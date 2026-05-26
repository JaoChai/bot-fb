import { useState, useEffect, useCallback } from 'react';
import { useParams, useSearchParams, useNavigate, useLocation } from 'react-router';
import {
  Loader2,
  Save,
  PanelRightClose,
  PanelRightOpen,
  Plus,
} from 'lucide-react';
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
import { FlowEditorTabsPanel, type EditorTab } from '@/components/flow-editor/FlowEditorTabsPanel';
import { FlowEditorMobileLayout, type MobileTab } from '@/components/flow-editor/FlowEditorMobileLayout';
import type { CreateFlowData, CreateFlowKnowledgeBaseData } from '@/types/api';

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
  const showEditor = Boolean(selectedFlowId || isCreatingNew);

  const editorTabs = (
    <FlowEditorTabsPanel
      activeTab={activeEditorTab}
      onTabChange={setActiveEditorTab}
      formData={formData}
      onFieldChange={handleFieldChange}
      onKnowledgeBasesChange={handleKnowledgeBasesChange}
      allKnowledgeBases={allKnowledgeBases}
      isLoadingKBs={isLoadingKBs}
      botId={botId}
      selectedFlowId={selectedFlowId}
    />
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
      <FlowEditorMobileLayout
        mobileActiveTab={mobileActiveTab}
        onMobileTabChange={setMobileActiveTab}
        formData={formData}
        hasChanges={hasChanges}
        isSaving={isSaving}
        botId={botId}
        selectedFlowId={selectedFlowId}
        flows={flows}
        isLoadingFlows={isLoadingFlows}
        isLoadingFlow={isLoadingFlow}
        showEditor={showEditor}
        isCreatingNew={isCreatingNew}
        editorTabsPanel={editorTabs}
        onSave={handleSave}
        chatMessages={chatMessages}
        isStreaming={isStreaming}
        onSendChatMessage={handleSendChatMessage}
        onCancelStream={cancelStream}
        onClearMessages={clearMessages}
        onNavigate={navigate}
      />
    </div>
  );
}

export default FlowEditorPage;
