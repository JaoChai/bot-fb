import { useState, useEffect, useRef, useCallback } from 'react';
import { useParams, useSearchParams, useNavigate, useLocation } from 'react-router';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { MarkdownToolbar } from '@/components/MarkdownToolbar';
import { KnowledgeBaseWarning } from '@/components/flow/KnowledgeBaseWarning';
import { PluginSection } from '@/components/flow/PluginSection';
import { useFlow, useCreateFlow, useUpdateFlow, useFlowOperations } from '@/hooks/useFlows';
import { useStreamingChat } from '@/hooks/useStreamingChat';
import { useAllKnowledgeBases } from '@/hooks/useKnowledgeBase';
import { useToast } from '@/hooks/use-toast';
import {
  FlowsList,
  KnowledgeBaseSelector,
  ChatEmulator,
  FlowSafetySettings,
  ToolCheckboxGrid,
  type KnowledgeBaseConfig,
} from '@/components/flows';
import type { AgentApprovalData } from '@/components/flows/AgentApprovalDialog';
import {
  Loader2,
  Save,
  Plus,
  ChevronDown,
  ChevronUp,
  Bot,
  HelpCircle,
  Code,
  Minimize2,
  List,
  Settings,
  MessageSquare,
  Sparkles,
  Brain,
  FileText,
} from 'lucide-react';
import type { CreateFlowData, CreateFlowKnowledgeBaseData } from '@/types/api';

type MobileTab = 'flows' | 'editor' | 'test';

function MobileBottomTabs({ activeTab, onTabChange }: { activeTab: MobileTab; onTabChange: (tab: MobileTab) => void }) {
  return (
    <div className="fixed bottom-0 left-0 right-0 border-t bg-background md:hidden z-50 pb-safe">
      <div className="grid grid-cols-3 h-14">
        <button
          onClick={() => onTabChange('flows')}
          className={cn(
            'flex flex-col items-center justify-center gap-0.5 transition-colors',
            activeTab === 'flows'
              ? 'text-foreground bg-muted'
              : 'text-muted-foreground hover:text-foreground'
          )}
        >
          <List className="h-5 w-5" />
          <span className="text-xs">Flows</span>
        </button>
        <button
          onClick={() => onTabChange('editor')}
          className={cn(
            'flex flex-col items-center justify-center gap-0.5 transition-colors',
            activeTab === 'editor'
              ? 'text-foreground bg-muted'
              : 'text-muted-foreground hover:text-foreground'
          )}
        >
          <Settings className="h-5 w-5" />
          <span className="text-xs">Editor</span>
        </button>
        <button
          onClick={() => onTabChange('test')}
          className={cn(
            'flex flex-col items-center justify-center gap-0.5 transition-colors',
            activeTab === 'test'
              ? 'text-foreground bg-muted'
              : 'text-muted-foreground hover:text-foreground'
          )}
        >
          <MessageSquare className="h-5 w-5" />
          <span className="text-xs">ทดสอบ</span>
        </button>
      </div>
    </div>
  );
}

function MobileHeader({ name, isDefault, onSave, isSaving, hasChanges }: {
  name: string;
  isDefault: boolean;
  onSave: () => void;
  isSaving: boolean;
  hasChanges: boolean;
}) {
  return (
    <div className="flex items-center justify-between px-4 py-3 border-b bg-background md:hidden">
      <div className="flex items-center gap-2 min-w-0 flex-1">
        <span className="font-bold text-lg truncate">
          {name || 'Flow ใหม่'}
        </span>
        {isDefault && (
          <span className="text-xs bg-muted px-2 py-0.5 rounded flex-shrink-0">Base</span>
        )}
      </div>
      <Button
        size="sm"
        onClick={onSave}
        disabled={isSaving || !hasChanges}
        className="flex-shrink-0"
      >
        {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
      </Button>
    </div>
  );
}

// Default system prompt for new flows
const DEFAULT_SYSTEM_PROMPT = `คุณคือผู้ช่วย AI ที่เป็นมิตรและช่วยเหลือลูกค้าอย่างมืออาชีพ

## บทบาทของคุณ:
- ตอบคำถามอย่างชัดเจน กระชับ และเป็นมิตร
- ให้ข้อมูลที่ถูกต้องและเป็นประโยชน์
- หากไม่ทราบคำตอบ ให้ยอมรับตรงๆ และแนะนำวิธีหาข้อมูลเพิ่มเติม

## แนวทางการสื่อสาร:
- ใช้ภาษาที่สุภาพและเข้าใจง่าย
- ตอบในภาษาเดียวกับที่ลูกค้าใช้
- ถามคำถามเพื่อทำความเข้าใจหากข้อมูลไม่ชัดเจน`;

// Initial form data constant
const INITIAL_FORM_DATA: CreateFlowData = {
  name: '',
  description: '',
  system_prompt: DEFAULT_SYSTEM_PROMPT,
  temperature: 0.7,
  max_tokens: 2048,
  agentic_mode: false,
  max_tool_calls: 10,
  enabled_tools: [],
  knowledge_bases: [],
  language: 'th',
  is_default: false,
  // Agent Safety
  agent_timeout_seconds: 120,
  agent_max_cost_per_request: null,
  hitl_enabled: false,
  hitl_dangerous_actions: [],
};

export function FlowEditorPage() {
  const { flowId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { toast } = useToast();

  // Support both botId and botid (case-insensitive)
  const botIdParam = searchParams.get('botId') || searchParams.get('botid');
  const parsedBotId = botIdParam ? parseInt(botIdParam, 10) : null;
  const botId = parsedBotId && !isNaN(parsedBotId) ? parsedBotId : null;

  // Detect if we're in editor entry mode (/flows/editor - no flowId)
  const isEditorEntryMode = location.pathname === '/flows/editor';

  // Get flows list
  const { flows, isLoading: isLoadingFlows, isSuccess: isFlowsSuccess } = useFlowOperations(botId);

  // Current flow being edited
  const parsedFlowId = flowId && flowId !== 'new' ? parseInt(flowId, 10) : null;
  const selectedFlowId = parsedFlowId && !isNaN(parsedFlowId) ? parsedFlowId : null;
  const isCreatingNew = flowId === 'new' || location.pathname === '/flows/new';

  // Fetch existing flow if editing
  const { data: existingFlow, isLoading: isLoadingFlow } = useFlow(botId, selectedFlowId);

  // Fetch all knowledge bases for multi-select
  const { data: allKnowledgeBases = [], isLoading: isLoadingKBs } = useAllKnowledgeBases();

  // Mutations
  const createMutation = useCreateFlow(botId);
  const updateMutation = useUpdateFlow(botId, selectedFlowId);

  // Form state
  const [formData, setFormData] = useState<CreateFlowData>(INITIAL_FORM_DATA);
  const [hasChanges, setHasChanges] = useState(false);

  // HITL approval state
  const [pendingApproval, setPendingApproval] = useState<AgentApprovalData | null>(null);

  // Streaming chat hook
  const {
    messages: chatMessages,
    isStreaming,
    sendMessage: sendStreamingMessage,
    cancelStream,
    clearMessages,
  } = useStreamingChat({
    botId,
    flowId: selectedFlowId,
    onApprovalRequired: useCallback((data: AgentApprovalData) => {
      setPendingApproval(data);
    }, []),
  });

  // Refs
  const systemPromptRef = useRef<HTMLTextAreaElement>(null);

  // Collapsible sections
  const [isBaseFlowInfoOpen, setIsBaseFlowInfoOpen] = useState(false);

  // Mobile navigation state
  const [mobileActiveTab, setMobileActiveTab] = useState<'flows' | 'editor' | 'test'>('editor');

  // Issue #56 - Flow Editor Improvements
  const [isSystemPromptPreview, setIsSystemPromptPreview] = useState(false);
  const [isFullscreenPrompt, setIsFullscreenPrompt] = useState(false);
  const [agenticSecondAIEnabled, setAgenticSecondAIEnabled] = useState(false);
  const [secondAIOptions, setSecondAIOptions] = useState({
    factCheck: false,
    policy: false,
    personality: false,
  });
  // plugins state removed - handled by PluginSection component
  const [externalDataSources, setExternalDataSources] = useState<string>('');

  // Auto-redirect in editor entry mode
  // Wait for isSuccess to ensure flows data is actually loaded (not just cached/stale)
  useEffect(() => {
    if (isEditorEntryMode && isFlowsSuccess && botId) {
      if (flows.length > 0) {
        // Find default flow or use first flow
        const defaultFlow = flows.find(f => f.is_default);
        const flowToLoad = defaultFlow || flows[0];
        navigate(`/flows/${flowToLoad.id}/edit?botId=${botId}`, { replace: true });
      } else {
        // No flows exist - redirect to create new
        navigate(`/flows/new?botId=${botId}`, { replace: true });
      }
    }
  }, [isEditorEntryMode, isFlowsSuccess, flows, botId, navigate]);

  // Load existing flow data
  useEffect(() => {
    if (existingFlow) {
      // Map knowledge_bases from existing flow
      const kbData: CreateFlowKnowledgeBaseData[] = existingFlow.knowledge_bases?.map(kb => ({
        id: kb.id,
        kb_top_k: kb.kb_top_k,
        kb_similarity_threshold: kb.kb_similarity_threshold,
      })) ?? [];

      setFormData({
        name: existingFlow.name,
        description: existingFlow.description || '',
        system_prompt: existingFlow.system_prompt,
        temperature: existingFlow.temperature,
        max_tokens: existingFlow.max_tokens,
        agentic_mode: existingFlow.agentic_mode,
        max_tool_calls: existingFlow.max_tool_calls,
        enabled_tools: existingFlow.enabled_tools || [],
        knowledge_bases: kbData,
        language: existingFlow.language,
        is_default: existingFlow.is_default,
        // Agent Safety
        agent_timeout_seconds: existingFlow.agent_timeout_seconds ?? 120,
        agent_max_cost_per_request: existingFlow.agent_max_cost_per_request ?? null,
        hitl_enabled: existingFlow.hitl_enabled ?? false,
        hitl_dangerous_actions: existingFlow.hitl_dangerous_actions || [],
      });

      // Load Second AI settings
      setAgenticSecondAIEnabled(existingFlow.second_ai_enabled ?? false);
      setSecondAIOptions({
        factCheck: existingFlow.second_ai_options?.fact_check ?? false,
        policy: existingFlow.second_ai_options?.policy ?? false,
        personality: existingFlow.second_ai_options?.personality ?? false,
      });

      setHasChanges(false);
    }
  }, [existingFlow]);

  // Handle form change
  const handleChange = <K extends keyof CreateFlowData>(field: K, value: CreateFlowData[K]) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setHasChanges(true);
  };

  // Validate Agentic Mode requires at least one tool
  const validateAgenticMode = (): string | null => {
    if (formData.agentic_mode) {
      if (!formData.enabled_tools || formData.enabled_tools.length === 0) {
        return 'กรุณาเลือกอย่างน้อย 1 tool เพื่อใช้งาน Agentic Mode';
      }
    }
    return null;
  };

  // Handle save
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

    // Validate Agentic Mode
    const agenticError = validateAgenticMode();
    if (agenticError) {
      toast({ title: 'ข้อผิดพลาด', description: agenticError, variant: 'destructive' });
      return;
    }

    // Prepare data with Second AI fields
    const dataToSave = {
      ...formData,
      second_ai_enabled: agenticSecondAIEnabled,
      second_ai_options: {
        fact_check: secondAIOptions.factCheck,
        policy: secondAIOptions.policy,
        personality: secondAIOptions.personality,
      },
    };

    try {
      if (selectedFlowId) {
        await updateMutation.mutateAsync(dataToSave);
        toast({ title: 'บันทึกแล้ว', description: 'การเปลี่ยนแปลงถูกบันทึกเรียบร้อย' });
      } else {
        const newFlow = await createMutation.mutateAsync(dataToSave);
        toast({ title: 'สร้างแล้ว', description: 'Flow ใหม่ถูกสร้างเรียบร้อย' });
        // Navigate to the new flow
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

  // Validate external data source URL
  const validateExternalDataSource = (url: string): boolean => {
    if (!url.trim()) return true; // empty is ok

    try {
      const parsed = new URL(url);
      // Only allow https
      if (parsed.protocol !== 'https:') {
        toast({
          title: 'Invalid URL',
          description: 'Only HTTPS URLs are allowed',
          variant: 'destructive',
        });
        return false;
      }
      // Prevent localhost/internal IPs
      if (['localhost', '127.0.0.1', '0.0.0.0'].includes(parsed.hostname)) {
        toast({
          title: 'Invalid URL',
          description: 'Internal URLs are not allowed',
          variant: 'destructive',
        });
        return false;
      }
      return true;
    } catch {
      toast({
        title: 'Invalid URL',
        description: 'Please enter a valid URL',
        variant: 'destructive',
      });
      return false;
    }
  };

  // Handle markdown toolbar actions
  const handleMarkdownAction = (action: string) => {
    const textarea = systemPromptRef.current;
    if (!textarea) return;

    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = formData.system_prompt;
    const selectedText = text.substring(start, end);

    let insertText = '';
    let finalText = '';

    switch (action) {
      case 'bold':
        insertText = selectedText ? `**${selectedText}**` : '**bold text**';
        break;
      case 'italic':
        insertText = selectedText ? `*${selectedText}*` : '*italic text*';
        break;
      case 'strikethrough':
        insertText = selectedText ? `~~${selectedText}~~` : '~~strikethrough text~~';
        break;
      case 'h1':
        insertText = selectedText ? `# ${selectedText}` : '# Heading 1';
        break;
      case 'h2':
        insertText = selectedText ? `## ${selectedText}` : '## Heading 2';
        break;
      case 'h3':
        insertText = selectedText ? `### ${selectedText}` : '### Heading 3';
        break;
      case 'bullet':
        insertText = selectedText ? `- ${selectedText}` : '- Bullet point';
        break;
      case 'numbered':
        insertText = selectedText ? `1. ${selectedText}` : '1. Numbered item';
        break;
      case 'code':
        insertText = selectedText ? `\`${selectedText}\`` : '`code`';
        break;
      case 'link':
        insertText = selectedText ? `[${selectedText}](url)` : '[link text](url)';
        break;
    }

    finalText = text.substring(0, start) + insertText + text.substring(end);
    handleChange('system_prompt', finalText);
    setHasChanges(true);
  };

  // Handle knowledge base selection change (memoized)
  const handleKnowledgeBasesChange = useCallback((kbs: KnowledgeBaseConfig[]) => {
    handleChange('knowledge_bases', kbs);
  }, []);

  // Handle chat emulator message send (memoized)
  const handleSendChatMessage = useCallback(async (message: string) => {
    await sendStreamingMessage(message);
  }, [sendStreamingMessage]);

  // Handle HITL approval dialog close
  const handleApprovalClose = useCallback(() => {
    setPendingApproval(null);
  }, []);

  // Show loading during editor entry mode redirect
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

  // No bot selected
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

  return (
    <div className="flex h-screen bg-background overflow-hidden">
      {/* ============ DESKTOP LAYOUT ============ */}
      {/* Left Sidebar - Flows List (Desktop) */}
      <div className="hidden md:block">
        <FlowsList
          flows={flows}
          isLoading={isLoadingFlows}
          selectedFlowId={selectedFlowId}
          botId={botId}
        />
      </div>

      {/* Main Content Area - Split into Editor + Chat Emulator (Desktop) */}
      <div className="hidden md:flex flex-1 overflow-hidden">
        {/* Editor Panel */}
        <div className="flex-1 flex flex-col overflow-hidden">
          {!showEditor ? (
            /* Empty State */
            <div className="flex-1 flex items-center justify-center">
              <p className="text-lg text-muted-foreground">เลือกหรือสร้างโฟลว์เพื่อเริ่มต้น</p>
            </div>
          ) : isLoadingFlow ? (
            <div className="flex-1 flex items-center justify-center">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <div className="flex-1 overflow-y-auto">
              <div className="max-w-4xl mx-auto p-6 space-y-6">
              {/* Header - Flow Name + Save Button */}
              <div className="flex items-center gap-4">
                <div className="flex-1">
                  <Input
                    placeholder="ชื่อโฟลว์"
                    value={formData.name}
                    onChange={(e) => handleChange('name', e.target.value)}
                    className="text-lg font-medium border-none shadow-none focus-visible:ring-0 px-0 h-auto text-xl"
                    disabled={formData.is_default}
                  />
                </div>
                <Button
                  onClick={handleSave}
                  disabled={isSaving || !hasChanges}
                >
                  {isSaving ? (
                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  ) : (
                    <Save className="h-4 w-4 mr-2" />
                  )}
                  บันทึกการเปลี่ยนแปลง
                </Button>
              </div>

              {/* Base Flow Info (Collapsible) */}
              {formData.is_default && (
                <Collapsible open={isBaseFlowInfoOpen} onOpenChange={setIsBaseFlowInfoOpen}>
                  <CollapsibleTrigger asChild>
                    <Button variant="ghost" className="w-full justify-start text-muted-foreground">
                      <HelpCircle className="h-4 w-4 mr-2" />
                      Base Flow คืออะไร?
                      {isBaseFlowInfoOpen ? (
                        <ChevronUp className="h-4 w-4 ml-auto" />
                      ) : (
                        <ChevronDown className="h-4 w-4 ml-auto" />
                      )}
                    </Button>
                  </CollapsibleTrigger>
                  <CollapsibleContent className="px-4 py-3 bg-muted/50 rounded-lg mt-2">
                    <p className="text-sm text-muted-foreground">
                      Base Flow คือ Flow เริ่มต้นที่จะถูกใช้งานเมื่อไม่มี Flow อื่นที่ตรงกับบริบทของการสนทนา
                      ทุก Bot ต้องมี Base Flow อย่างน้อย 1 ตัว
                    </p>
                  </CollapsibleContent>
                </Collapsible>
              )}

              {/* Section: AI Chatbot */}
              <div className="border rounded-lg p-6 space-y-6">
                <div className="flex items-center gap-2 text-muted-foreground">
                  <Bot className="h-5 w-5" />
                  <span className="font-medium">AI Chatbot</span>
                </div>

                {/* ตั้งค่าพื้นฐาน */}
                <div className="flex items-center gap-2">
                  <span className="text-sm font-medium text-muted-foreground">ตั้งค่าพื้นฐาน</span>
                  <Badge variant="outline" className="text-[10px]">ทุกโหมด</Badge>
                </div>

                {/* Knowledge Bases (Multi-Select) */}
                <KnowledgeBaseSelector
                  allKnowledgeBases={allKnowledgeBases}
                  selectedKnowledgeBases={formData.knowledge_bases || []}
                  isLoading={isLoadingKBs}
                  onChange={handleKnowledgeBasesChange}
                />

                {/* System Prompt */}
                <div className="space-y-3 border rounded-lg overflow-hidden">
                  <div className="px-4 pt-4 pb-2">
                    <div className="flex items-center gap-2 mb-3">
                      <span className="text-sm font-medium">
                        เขียนคำสั่งให้ AI สร้างการตอบกลับ - คุณสามารถดูตัวอย่างการเขียนคำสั่งได้ใน{' '}
                        <a href="#" className="underline hover:text-muted-foreground">
                          คู่มือการใช้งาน & Prompts Library
                        </a>
                      </span>
                    </div>
                    {/* Markdown Toolbar */}
                    <MarkdownToolbar
                      onBold={() => handleMarkdownAction('bold')}
                      onItalic={() => handleMarkdownAction('italic')}
                      onStrikethrough={() => handleMarkdownAction('strikethrough')}
                      onHeading={(level) => handleMarkdownAction(`h${level}`)}
                      onBulletList={() => handleMarkdownAction('bullet')}
                      onNumberedList={() => handleMarkdownAction('numbered')}
                      onLink={() => handleMarkdownAction('link')}
                      onCode={() => handleMarkdownAction('code')}
                      onPreviewToggle={() => setIsSystemPromptPreview(!isSystemPromptPreview)}
                      onFullscreen={() => setIsFullscreenPrompt(true)}
                      isPreviewMode={isSystemPromptPreview}
                    />
                  </div>
                  {!isSystemPromptPreview ? (
                    <>
                      <Textarea
                        ref={systemPromptRef}
                        placeholder="คุณคือผู้ช่วยที่เป็นมิตร..."
                        className="min-h-[300px] max-h-[500px] overflow-y-auto font-mono text-sm border-0 rounded-none focus-visible:ring-0 resize-y"
                        value={formData.system_prompt}
                        onChange={(e) => handleChange('system_prompt', e.target.value)}
                      />
                      <div className="flex justify-end gap-4 text-xs text-muted-foreground px-4 pb-4">
                        <span>lines: {formData.system_prompt.split('\n').length}</span>
                        <span>words: {formData.system_prompt.split(/\s+/).filter(Boolean).length}</span>
                      </div>
                    </>
                  ) : (
                    <div className="px-4 py-4 min-h-[300px] bg-muted/30 prose prose-sm max-w-none">
                      <p className="text-sm text-muted-foreground">Preview mode coming soon</p>
                    </div>
                  )}
                </div>

                {/* Temperature */}
                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <Label>Temperature: {formData.temperature}</Label>
                    <span className="text-xs text-muted-foreground">
                      ต่ำ = ตอบตรงประเด็น, สูง = ตอบสร้างสรรค์
                    </span>
                  </div>
                  <Slider
                    value={[formData.temperature || 0.7]}
                    onValueChange={([v]) => handleChange('temperature', v)}
                    min={0}
                    max={1}
                    step={0.1}
                  />
                </div>

                {/* Max Tokens */}
                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <Label>Max Tokens: {formData.max_tokens}</Label>
                    <span className="text-xs text-muted-foreground">
                      ความยาวสูงสุดของคำตอบ AI
                    </span>
                  </div>
                  <div className="flex items-center gap-3">
                    <Slider
                      value={[formData.max_tokens || 2048]}
                      onValueChange={([v]) => handleChange('max_tokens', v)}
                      min={512}
                      max={16384}
                      step={256}
                    />
                    <Input
                      type="number"
                      min={512}
                      max={16384}
                      step={256}
                      value={formData.max_tokens}
                      onChange={(e) => {
                        const val = parseInt(e.target.value, 10);
                        if (!isNaN(val)) handleChange('max_tokens', val);
                      }}
                      className="w-24"
                    />
                  </div>
                </div>

                {/* โหมดขั้นสูง */}
                <div className="border-t" />
                <div className="flex items-center gap-2">
                  <span className="text-sm font-medium text-muted-foreground">โหมดขั้นสูง</span>
                  <Badge variant="secondary" className="text-[10px]">Agentic</Badge>
                </div>

                {/* Agentic Mode Toggle */}
                <div className="flex items-start gap-4 p-4 border rounded-lg">
                  <Switch
                    checked={formData.agentic_mode}
                    onCheckedChange={(checked) => handleChange('agentic_mode', checked)}
                  />
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <span className="font-medium">Agentic Mode</span>
                      <Badge variant="secondary">
                        AI ที่ฉลาดขึ้น
                      </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground mt-1">
                      เปลี่ยน AI ธรรมดาให้เป็น AI Agent ที่สามารถค้นหาข้อมูล เรียกใช้ tools และตัดสินใจได้อย่างอัตโนมัติ
                    </p>

                    {formData.agentic_mode && (
                      <div className="mt-4 space-y-4">
                        {/* Tool Selection */}
                        <div className="space-y-2">
                          <Label className="text-sm font-medium">เลือก Tools ที่ AI สามารถใช้ได้</Label>
                          <ToolCheckboxGrid
                            enabledTools={formData.enabled_tools || []}
                            onChange={(tools) => handleChange('enabled_tools', tools)}
                          />
                          {(!formData.enabled_tools || formData.enabled_tools.length === 0) && (
                            <div className="flex items-center gap-2 p-3 mt-2 rounded-lg border border-orange-300 bg-orange-50 dark:border-orange-700 dark:bg-orange-950/30">
                              <svg className="h-4 w-4 text-orange-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                              </svg>
                              <p className="text-xs text-orange-700 dark:text-orange-300">
                                กรุณาเลือกอย่างน้อย 1 tool เพื่อใช้งาน Agentic Mode
                              </p>
                            </div>
                          )}
                        </div>

                        {/* Max Tool Calls */}
                        <div className="flex items-center gap-4">
                          <Label className="text-sm">จำนวนครั้งสูงสุดในการเรียกใช้ Tools</Label>
                          <Input
                            type="number"
                            min={5}
                            max={15}
                            value={formData.max_tool_calls}
                            onChange={(e) => {
                              const val = parseInt(e.target.value, 10);
                              if (!isNaN(val)) handleChange('max_tool_calls', val);
                            }}
                            className="w-20"
                          />
                          <span className="text-xs text-muted-foreground">
                            5-15 ครั้ง • AI จะหยุดทำงานอัตโนมัติเมื่อถึงจำนวนนี้
                          </span>
                        </div>

                        {/* Max Tokens */}
                        <div className="flex items-center gap-4">
                          <Label className="text-sm">Max Tokens (ความยาวคำตอบ)</Label>
                          <Input
                            type="number"
                            min={512}
                            max={8192}
                            step={256}
                            value={formData.max_tokens}
                            onChange={(e) => {
                              const val = parseInt(e.target.value, 10);
                              if (!isNaN(val)) handleChange('max_tokens', val);
                            }}
                            className="w-24"
                          />
                          <span className="text-xs text-muted-foreground">
                            512-8192 • กำหนดความยาวสูงสุดของคำตอบ AI
                          </span>
                        </div>

                        {/* Agent Safety Settings */}
                        <div className="mt-4 pt-4 border-t">
                          <FlowSafetySettings
                            settings={{
                              agent_timeout_seconds: formData.agent_timeout_seconds ?? 120,
                              agent_max_cost_per_request: formData.agent_max_cost_per_request ?? null,
                              hitl_enabled: formData.hitl_enabled ?? false,
                              hitl_dangerous_actions: formData.hitl_dangerous_actions || [],
                            }}
                            onChange={(field, value) => handleChange(field as keyof typeof formData, value as CreateFlowData[keyof CreateFlowData])}
                          />
                        </div>
                      </div>
                    )}
                  </div>
                </div>

                {/* AI Enhancement Features Section */}
                <div className="border rounded-lg p-6 space-y-4">
                  <div className="flex items-center gap-2 text-muted-foreground">
                    <Sparkles className="h-5 w-5" />
                    <span className="font-medium">AI Enhancement</span>
                    <Badge variant="outline" className="text-xs">Auto</Badge>
                  </div>

                  <p className="text-sm text-muted-foreground">
                    ฟีเจอร์เหล่านี้ทำงานอัตโนมัติเพื่อให้ AI ตอบได้ดีขึ้น
                  </p>

                  {/* Chain-of-Thought Indicator */}
                  <div className="flex items-start gap-3 p-3 bg-muted/30 rounded-lg">
                    <div className="p-2 bg-purple-500/10 rounded">
                      <Brain className="h-4 w-4 text-purple-500" />
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">Chain-of-Thought</span>
                        <Badge variant="secondary" className="text-xs">Active</Badge>
                      </div>
                      <p className="text-xs text-muted-foreground mt-1">
                        เมื่อตรวจพบคำถามซับซ้อน AI จะวิเคราะห์ทีละขั้นตอนก่อนตอบ
                      </p>
                    </div>
                  </div>

                  {/* Contextual Retrieval Indicator */}
                  <div className="flex items-start gap-3 p-3 bg-muted/30 rounded-lg">
                    <div className="p-2 bg-blue-500/10 rounded">
                      <FileText className="h-4 w-4 text-blue-500" />
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">Contextual Retrieval</span>
                        <Badge variant="secondary" className="text-xs">Active</Badge>
                      </div>
                      <p className="text-xs text-muted-foreground mt-1">
                        Documents ใหม่จะมี context เพิ่ม ช่วยให้ค้นหาได้แม่นยำขึ้น 49%
                      </p>
                    </div>
                  </div>
                </div>

                {/* Set as Default */}
                <div className="flex items-center gap-3 pt-4 border-t">
                  <Switch
                    id="is_default"
                    checked={formData.is_default}
                    onCheckedChange={(checked) => handleChange('is_default', checked)}
                  />
                  <Label htmlFor="is_default">ตั้งเป็น Flow เริ่มต้น</Label>
                </div>
              </div>

              {/* Issue #56: Second AI for Improvement */}
              <div className="border rounded-lg p-6">
                <div className="flex items-start gap-4">
                  <Switch
                    id="agentic_second_ai"
                    checked={agenticSecondAIEnabled}
                    onCheckedChange={(checked) => {
                      setAgenticSecondAIEnabled(checked);
                      setHasChanges(true);
                    }}
                  />
                  <div className="flex-1">
                    <Label htmlFor="agentic_second_ai" className="font-medium">
                      Second AI for Improvement
                    </Label>
                    <p className="text-sm text-muted-foreground mt-1">
                      ใช้ AI ตัวที่สองเพื่อตรวจสอบและปรับปรุงคำตอบ เช่น การตรวจสอบข้อเท็จจริง นโยบาย หรือบุคลิกภาพ
                    </p>
                  </div>
                </div>

                {agenticSecondAIEnabled && (
                  <div className="mt-4 space-y-3 pt-4 border-t">
                    <div className="grid grid-cols-3 gap-3">
                      <label className="flex items-center gap-2 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={secondAIOptions.factCheck}
                          onChange={(e) => {
                            setSecondAIOptions(prev => ({ ...prev, factCheck: e.target.checked }));
                            setHasChanges(true);
                          }}
                          className="rounded border-border"
                        />
                        <span className="text-sm">✓ Fact Check</span>
                      </label>
                      <label className="flex items-center gap-2 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={secondAIOptions.policy}
                          onChange={(e) => {
                            setSecondAIOptions(prev => ({ ...prev, policy: e.target.checked }));
                            setHasChanges(true);
                          }}
                          className="rounded border-border"
                        />
                        <span className="text-sm">🚦 Policy</span>
                      </label>
                      <label className="flex items-center gap-2 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={secondAIOptions.personality}
                          onChange={(e) => {
                            setSecondAIOptions(prev => ({ ...prev, personality: e.target.checked }));
                            setHasChanges(true);
                          }}
                          className="rounded border-border"
                        />
                        <span className="text-sm">💬 Personality</span>
                      </label>
                    </div>

                    {/* Warning: Fact Check requires Knowledge Base */}
                    <KnowledgeBaseWarning
                      visible={secondAIOptions.factCheck && (formData.knowledge_bases?.length ?? 0) === 0}
                    />
                  </div>
                )}
              </div>

              {/* Issue #56: External Data Sources */}
              <div className="border rounded-lg p-6">
                <div className="flex items-start gap-4">
                  <div className="flex-1">
                    <Label className="font-medium flex items-center gap-2">
                      <Code className="h-4 w-4" />
                      External Data Sources
                    </Label>
                    <p className="text-sm text-muted-foreground mt-1">
                      เชื่อมต่อแหล่งข้อมูลภายนอกเพื่อให้ AI สามารถเรียกใช้ข้อมูลแบบ Real-time
                    </p>
                  </div>
                </div>

                <div className="mt-4">
                  <Input
                    placeholder="ค้นหาหรือใส่ URL ของ API endpoint..."
                    value={externalDataSources}
                    onChange={(e) => {
                      const value = e.target.value;
                      if (validateExternalDataSource(value)) {
                        setExternalDataSources(value);
                        setHasChanges(true);
                      }
                    }}
                  />
                  <p className="text-xs text-muted-foreground mt-2">
                    • JSON API endpoints ต่าง ๆ สามารถใช้ได้ (HTTPS only)
                    <br />• ใช้ `{'{'}data{'}'}` syntax ในคำสั่ง AI เพื่อเรียกใช้ข้อมูล
                  </p>
                </div>
              </div>

              {/* Plugins - Managed by PluginSection */}
              <PluginSection botId={String(botId)} flowId={selectedFlowId} />

            </div>
          </div>
        )}
        </div>

        {/* Chat Emulator - Right Panel (Desktop) */}
        <ChatEmulator
          messages={chatMessages}
          isStreaming={isStreaming}
          onSendMessage={handleSendChatMessage}
          onCancelStream={cancelStream}
          onClearMessages={clearMessages}
          pendingApproval={pendingApproval}
          onApprovalClose={handleApprovalClose}
          disabled={!selectedFlowId}
          disabledReason={!selectedFlowId ? 'บันทึก Flow ก่อนทดสอบ' : undefined}
        />
      </div>

      {/* ============ MOBILE LAYOUT ============ */}
      <div className="flex flex-col flex-1 md:hidden pb-14">
        {/* Mobile Header */}
        <MobileHeader
          name={formData.name}
          isDefault={formData.is_default ?? false}
          onSave={handleSave}
          isSaving={isSaving}
          hasChanges={hasChanges}
        />

        {/* Mobile Tab Content */}
        <div className="flex-1 overflow-hidden">
          {/* Flows Tab */}
          {mobileActiveTab === 'flows' && (
            <div className="h-full overflow-y-auto">
              {/* Create New Flow Button */}
              <div className="p-4 border-b">
                <Button
                  className="w-full"
                  variant="default"
                  onClick={() => navigate(`/flows/new?botId=${botId}`)}
                >
                  <Plus className="h-4 w-4 mr-2" />
                  สร้างโฟลว์ใหม่
                </Button>
              </div>

              {/* Flow List */}
              {isLoadingFlows ? (
                <div className="flex justify-center py-8">
                  <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                </div>
              ) : flows.length === 0 ? (
                <p className="text-sm text-muted-foreground text-center py-8">ยังไม่มี Flow</p>
              ) : (
                <div className="p-2 space-y-1">
                  {[...flows].sort((a, b) => {
                    if (a.is_default && !b.is_default) return -1;
                    if (!a.is_default && b.is_default) return 1;
                    return 0;
                  }).map((flow) => (
                    <button
                      key={flow.id}
                      onClick={() => {
                        navigate(`/flows/${flow.id}/edit?botId=${botId}`);
                        setMobileActiveTab('editor');
                      }}
                      className={cn(
                        'w-full text-left px-4 py-3 rounded-lg text-sm transition-all',
                        flow.is_default ? 'border-l-4 border-l-foreground' : 'border-l-4 border-l-transparent',
                        selectedFlowId === flow.id
                          ? 'bg-foreground text-background font-medium'
                          : 'hover:bg-muted'
                      )}
                    >
                      <div className="flex items-center gap-2">
                        {flow.is_default && (
                          <svg className={cn('h-4 w-4 shrink-0', selectedFlowId === flow.id ? 'text-background' : 'text-foreground')} fill="currentColor" viewBox="0 0 20 20">
                            <path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v14l-5-2.5L5 18V4z" />
                          </svg>
                        )}
                        <span className="truncate font-medium">{flow.name}</span>
                      </div>
                      {flow.is_default && (
                        <span className={cn('text-xs mt-1 block ml-6', selectedFlowId === flow.id ? 'text-background/70' : 'text-muted-foreground')}>
                          Base Flow
                        </span>
                      )}
                    </button>
                  ))}
                </div>
              )}

              {/* Bottom Actions */}
              <div className="p-4 border-t mt-auto space-y-2">
                <Button
                  variant="outline"
                  size="sm"
                  className="w-full justify-start"
                  onClick={() => navigate(`/bots/${botId}/edit`)}
                >
                  <Settings className="h-4 w-4 mr-2" />
                  แก้ไขการเชื่อมต่อ
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  className="w-full justify-start"
                  onClick={() => navigate('/bots')}
                >
                  <List className="h-4 w-4 mr-2" />
                  กลับไปหน้า Bots
                </Button>
              </div>
            </div>
          )}

          {/* Editor Tab */}
          {mobileActiveTab === 'editor' && (
            <div className="h-full overflow-y-auto">
              {!showEditor ? (
                <div className="flex-1 flex flex-col items-center justify-center h-full p-8">
                  <p className="text-muted-foreground text-center mb-4">เลือกหรือสร้างโฟลว์เพื่อเริ่มต้น</p>
                  <Button onClick={() => setMobileActiveTab('flows')}>
                    <List className="h-4 w-4 mr-2" />
                    ดู Flows ทั้งหมด
                  </Button>
                </div>
              ) : isLoadingFlow ? (
                <div className="flex items-center justify-center h-full">
                  <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
                </div>
              ) : (
                <div className="p-4 space-y-4">
                  {/* Flow Name Input (Mobile) */}
                  <div className="space-y-2">
                    <Label>ชื่อโฟลว์</Label>
                    <Input
                      placeholder="ชื่อโฟลว์"
                      value={formData.name}
                      onChange={(e) => handleChange('name', e.target.value)}
                      disabled={formData.is_default}
                      className="text-base"
                    />
                  </div>

                  {/* ตั้งค่าพื้นฐาน */}
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-muted-foreground">ตั้งค่าพื้นฐาน</span>
                    <Badge variant="outline" className="text-[10px]">ทุกโหมด</Badge>
                  </div>

                  {/* Knowledge Base Selector (Mobile) */}
                  <KnowledgeBaseSelector
                    allKnowledgeBases={allKnowledgeBases}
                    selectedKnowledgeBases={formData.knowledge_bases || []}
                    isLoading={isLoadingKBs}
                    onChange={handleKnowledgeBasesChange}
                  />

                  {/* System Prompt (Mobile) */}
                  <div className="space-y-2">
                    <Label>System Prompt</Label>
                    <Textarea
                      placeholder="คุณคือผู้ช่วยที่เป็นมิตร..."
                      className="min-h-[200px] text-base font-mono"
                      value={formData.system_prompt}
                      onChange={(e) => handleChange('system_prompt', e.target.value)}
                    />
                  </div>

                  {/* Temperature (Mobile) */}
                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <Label>Temperature: {formData.temperature}</Label>
                    </div>
                    <Slider
                      value={[formData.temperature || 0.7]}
                      onValueChange={([v]) => handleChange('temperature', v)}
                      min={0}
                      max={1}
                      step={0.1}
                    />
                    <p className="text-xs text-muted-foreground">
                      ต่ำ = ตอบตรงประเด็น, สูง = สร้างสรรค์
                    </p>
                  </div>

                  {/* Max Tokens (Mobile) */}
                  <div className="space-y-2">
                    <div className="flex items-center justify-between">
                      <Label>Max Tokens: {formData.max_tokens}</Label>
                    </div>
                    <div className="flex items-center gap-3">
                      <Slider
                        value={[formData.max_tokens || 2048]}
                        onValueChange={([v]) => handleChange('max_tokens', v)}
                        min={512}
                        max={16384}
                        step={256}
                      />
                      <Input
                        type="number"
                        min={512}
                        max={16384}
                        step={256}
                        value={formData.max_tokens}
                        onChange={(e) => {
                          const val = parseInt(e.target.value, 10);
                          if (!isNaN(val)) handleChange('max_tokens', val);
                        }}
                        className="w-20 h-8 text-sm"
                      />
                    </div>
                    <p className="text-xs text-muted-foreground">
                      ความยาวสูงสุดของคำตอบ AI
                    </p>
                  </div>

                  {/* โหมดขั้นสูง */}
                  <div className="border-t my-2" />
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-muted-foreground">โหมดขั้นสูง</span>
                    <Badge variant="secondary" className="text-[10px]">Agentic</Badge>
                  </div>

                  {/* Agentic Mode (Mobile) */}
                  <div className="border rounded-lg p-4 space-y-4">
                    <div className="flex items-start gap-3">
                      <Switch
                        checked={formData.agentic_mode}
                        onCheckedChange={(checked) => handleChange('agentic_mode', checked)}
                      />
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <span className="font-medium text-sm">Agentic Mode</span>
                          <Badge variant="secondary" className="text-xs">AI ฉลาดขึ้น</Badge>
                        </div>
                        <p className="text-xs text-muted-foreground mt-1">
                          AI สามารถค้นหาและตัดสินใจได้อัตโนมัติ
                        </p>
                      </div>
                    </div>

                    {formData.agentic_mode && (
                      <div className="space-y-3 pt-3 border-t">
                        <Label className="text-xs">เลือก Tools</Label>
                        <ToolCheckboxGrid
                          enabledTools={formData.enabled_tools || []}
                          onChange={(tools) => handleChange('enabled_tools', tools)}
                          compact
                        />

                        {/* Max Tool Calls (Mobile) */}
                        <div className="flex items-center gap-3">
                          <Label className="text-xs">Tool Calls สูงสุด</Label>
                          <Input
                            type="number"
                            min={5}
                            max={15}
                            value={formData.max_tool_calls}
                            onChange={(e) => {
                              const val = parseInt(e.target.value, 10);
                              if (!isNaN(val)) handleChange('max_tool_calls', val);
                            }}
                            className="w-16 h-8 text-sm"
                          />
                        </div>

                        {/* Max Tokens (Mobile) */}
                        <div className="flex items-center gap-3">
                          <Label className="text-xs">Max Tokens</Label>
                          <Input
                            type="number"
                            min={512}
                            max={8192}
                            step={256}
                            value={formData.max_tokens}
                            onChange={(e) => {
                              const val = parseInt(e.target.value, 10);
                              if (!isNaN(val)) handleChange('max_tokens', val);
                            }}
                            className="w-20 h-8 text-sm"
                          />
                        </div>

                        {/* Agent Safety Settings (Mobile) */}
                        <div className="mt-3 pt-3 border-t">
                          <FlowSafetySettings
                            settings={{
                              agent_timeout_seconds: formData.agent_timeout_seconds ?? 120,
                              agent_max_cost_per_request: formData.agent_max_cost_per_request ?? null,
                              hitl_enabled: formData.hitl_enabled ?? false,
                              hitl_dangerous_actions: formData.hitl_dangerous_actions || [],
                            }}
                            onChange={(field, value) => handleChange(field as keyof typeof formData, value as CreateFlowData[keyof CreateFlowData])}
                          />
                        </div>
                      </div>
                    )}
                  </div>

                  {/* AI Enhancement Features (Mobile) */}
                  <div className="border rounded-lg p-4 space-y-3">
                    <div className="flex items-center gap-2 text-muted-foreground">
                      <Sparkles className="h-4 w-4" />
                      <span className="font-medium text-sm">AI Enhancement</span>
                      <Badge variant="outline" className="text-xs">Auto</Badge>
                    </div>

                    <p className="text-xs text-muted-foreground">
                      ฟีเจอร์เหล่านี้ทำงานอัตโนมัติเพื่อให้ AI ตอบได้ดีขึ้น
                    </p>

                    <div className="space-y-2">
                      <div className="flex items-center gap-2 p-2 bg-muted/30 rounded">
                        <Brain className="h-4 w-4 text-purple-500" />
                        <span className="text-xs font-medium">Chain-of-Thought</span>
                        <Badge variant="secondary" className="text-xs ml-auto">Active</Badge>
                      </div>
                      <div className="flex items-center gap-2 p-2 bg-muted/30 rounded">
                        <FileText className="h-4 w-4 text-blue-500" />
                        <span className="text-xs font-medium">Contextual Retrieval</span>
                        <Badge variant="secondary" className="text-xs ml-auto">Active</Badge>
                      </div>
                    </div>
                  </div>

                  {/* Set as Default (Mobile) */}
                  <div className="flex items-center gap-3 p-4 border rounded-lg">
                    <Switch
                      id="is_default_mobile"
                      checked={formData.is_default}
                      onCheckedChange={(checked) => handleChange('is_default', checked)}
                    />
                    <Label htmlFor="is_default_mobile">ตั้งเป็น Flow เริ่มต้น</Label>
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Test Chat Tab */}
          {mobileActiveTab === 'test' && (
            <div className="h-full flex flex-col">
              <ChatEmulator
                messages={chatMessages}
                isStreaming={isStreaming}
                onSendMessage={handleSendChatMessage}
                onCancelStream={cancelStream}
                onClearMessages={clearMessages}
                pendingApproval={pendingApproval}
                onApprovalClose={handleApprovalClose}
                disabled={!selectedFlowId}
                disabledReason={!selectedFlowId ? 'บันทึก Flow ก่อนทดสอบ' : undefined}
                className="flex-1 w-full border-0"
              />
            </div>
          )}
        </div>

        {/* Mobile Bottom Tabs */}
        <MobileBottomTabs activeTab={mobileActiveTab} onTabChange={setMobileActiveTab} />
      </div>

      {/* Unsaved changes toast */}
      {hasChanges && (
        <div className="fixed bottom-20 md:bottom-4 left-1/2 -translate-x-1/2 bg-background border rounded-lg shadow-lg px-4 py-3 flex items-center gap-4 z-50">
          <span className="text-sm text-muted-foreground hidden sm:inline">มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก</span>
          <span className="text-sm text-muted-foreground sm:hidden">ยังไม่ได้บันทึก</span>
          <div className="flex gap-2">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                if (existingFlow) {
                  // Map knowledge_bases from existing flow
                  const kbData: CreateFlowKnowledgeBaseData[] = existingFlow.knowledge_bases?.map(kb => ({
                    id: kb.id,
                    kb_top_k: kb.kb_top_k,
                    kb_similarity_threshold: kb.kb_similarity_threshold,
                  })) ?? [];

                  setFormData({
                    name: existingFlow.name,
                    description: existingFlow.description || '',
                    system_prompt: existingFlow.system_prompt,
                    temperature: existingFlow.temperature,
                    max_tokens: existingFlow.max_tokens,
                    agentic_mode: existingFlow.agentic_mode,
                    max_tool_calls: existingFlow.max_tool_calls,
                    enabled_tools: existingFlow.enabled_tools || [],
                    knowledge_bases: kbData,
                    language: existingFlow.language,
                    is_default: existingFlow.is_default,
                    // Agent Safety
                    agent_timeout_seconds: existingFlow.agent_timeout_seconds ?? 120,
                    agent_max_cost_per_request: existingFlow.agent_max_cost_per_request ?? null,
                    hitl_enabled: existingFlow.hitl_enabled ?? false,
                    hitl_dangerous_actions: existingFlow.hitl_dangerous_actions || [],
                  });
                } else {
                  setFormData(INITIAL_FORM_DATA);
                }
                setHasChanges(false);
              }}
            >
              ยกเลิก
            </Button>
            <Button size="sm" onClick={handleSave} disabled={isSaving}>
              {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : 'บันทึก'}
            </Button>
          </div>
        </div>
      )}

      {/* Fullscreen System Prompt Editor Dialog */}
      <Dialog open={isFullscreenPrompt} onOpenChange={setIsFullscreenPrompt}>
        <DialogContent className="max-w-[95vw] w-[95vw] h-[90vh] flex flex-col p-0">
          <DialogHeader className="px-6 py-4 border-b flex-shrink-0">
            <div className="flex items-center justify-between">
              <DialogTitle className="text-lg font-semibold">
                แก้ไข System Prompt - {formData.name || 'Flow ใหม่'}
              </DialogTitle>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => setIsFullscreenPrompt(false)}
                className="h-8 w-8 p-0"
              >
                <Minimize2 className="h-4 w-4" />
              </Button>
            </div>
          </DialogHeader>
          <div className="flex-1 flex flex-col overflow-hidden p-6">
            <Textarea
              placeholder="คุณคือผู้ช่วยที่เป็นมิตร..."
              className="flex-1 font-mono text-sm resize-none"
              value={formData.system_prompt}
              onChange={(e) => handleChange('system_prompt', e.target.value)}
            />
            <div className="flex justify-between items-center mt-4 pt-4 border-t">
              <div className="flex gap-4 text-xs text-muted-foreground">
                <span>lines: {formData.system_prompt.split('\n').length}</span>
                <span>words: {formData.system_prompt.split(/\s+/).filter(Boolean).length}</span>
              </div>
              <Button
                onClick={() => setIsFullscreenPrompt(false)}
              >
                เสร็จสิ้น
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
