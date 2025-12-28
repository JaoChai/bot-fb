import { useState, useEffect, useRef } from 'react';
import { useParams, useSearchParams, useNavigate, useLocation } from 'react-router';
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
import { MarkdownToolbar } from '@/components/MarkdownToolbar';
import { useFlow, useCreateFlow, useUpdateFlow, useFlowOperations } from '@/hooks/useFlows';
import { useStreamingChat } from '@/hooks/useStreamingChat';
import { useAllKnowledgeBases } from '@/hooks/useKnowledgeBase';
import { useToast } from '@/hooks/use-toast';
import { ThinkingDisplay } from '@/components/ThinkingDisplay';
import {
  Loader2,
  Save,
  Plus,
  MessageCircle,
  Send,
  ChevronDown,
  ChevronUp,
  Link2,
  Settings,
  ArrowLeft,
  Bot,
  HelpCircle,
  BookOpen,
  X,
  Zap,
  Paperclip,
  Image as ImageIcon,
  Trash2,
  Code,
  Square,
  Brain,
} from 'lucide-react';
import type { CreateFlowData, CreateFlowKnowledgeBaseData } from '@/types/api';

// Helper to generate unique IDs
function generateId() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
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
  model: 'google/gemini-2.0-flash-exp:free',
  fallback_model: 'openai/gpt-4o-mini',
  decision_model: 'openai/gpt-4o-mini',
  fallback_decision_model: 'google/gemini-2.0-flash-exp:free',
  temperature: 0.7,
  max_tokens: 2048,
  agentic_mode: false,
  max_tool_calls: 10,
  enabled_tools: [],
  knowledge_bases: [],
  language: 'th',
  is_default: false,
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

  // Streaming chat hook
  const {
    messages: chatMessages,
    isStreaming,
    enableThinking,
    setEnableThinking,
    sendMessage: sendStreamingMessage,
    cancelStream,
    clearMessages,
  } = useStreamingChat({ botId, flowId: selectedFlowId });

  // Chat input state
  const [chatInput, setChatInput] = useState('');
  const chatEndRef = useRef<HTMLDivElement>(null);
  const systemPromptRef = useRef<HTMLTextAreaElement>(null);

  // Collapsible sections
  const [isBaseFlowInfoOpen, setIsBaseFlowInfoOpen] = useState(false);

  // Issue #56 - Flow Editor Improvements
  const [isSystemPromptPreview, setIsSystemPromptPreview] = useState(false);
  const [agenticSecondAIEnabled, setAgenticSecondAIEnabled] = useState(false);
  const [secondAIOptions, setSecondAIOptions] = useState({
    factCheck: false,
    policy: false,
    personality: false,
  });
  const [plugins, setPlugins] = useState<Array<{ id: string; name: string }>>([]);
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
        model: existingFlow.model,
        fallback_model: existingFlow.fallback_model || '',
        decision_model: existingFlow.decision_model || '',
        fallback_decision_model: existingFlow.fallback_decision_model || '',
        temperature: existingFlow.temperature,
        max_tokens: existingFlow.max_tokens,
        agentic_mode: existingFlow.agentic_mode,
        max_tool_calls: existingFlow.max_tool_calls,
        enabled_tools: existingFlow.enabled_tools || [],
        knowledge_bases: kbData,
        language: existingFlow.language,
        is_default: existingFlow.is_default,
      });
      setHasChanges(false);
    }
  }, [existingFlow]);


  // Scroll to bottom of chat
  useEffect(() => {
    chatEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [chatMessages]);

  // Handle form change
  const handleChange = <K extends keyof CreateFlowData>(field: K, value: CreateFlowData[K]) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setHasChanges(true);
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

    try {
      if (selectedFlowId) {
        await updateMutation.mutateAsync(formData);
        toast({ title: 'บันทึกแล้ว', description: 'การเปลี่ยนแปลงถูกบันทึกเรียบร้อย' });
      } else {
        const newFlow = await createMutation.mutateAsync(formData);
        toast({ title: 'สร้างแล้ว', description: 'Flow ใหม่ถูกสร้างเรียบร้อย' });
        // Navigate to the new flow
        if (newFlow?.id) {
          navigate(`/flows/${newFlow.id}/edit?botId=${botId}`);
        }
      }
      setHasChanges(false);
    } catch (err) {
      console.error('Failed to save flow:', err);
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

  // Handle chat emulator - uses streaming API
  const handleSendMessage = async () => {
    if (!chatInput.trim()) return;
    if (!selectedFlowId) {
      toast({
        title: 'ยังไม่ได้บันทึก Flow',
        description: 'กรุณาบันทึก Flow ก่อนทดสอบ',
        variant: 'destructive',
      });
      return;
    }

    const userMessage = chatInput.trim();
    setChatInput('');

    // Use streaming hook to send message
    await sendStreamingMessage(userMessage);
  };

  // Show loading during editor entry mode redirect
  if (isEditorEntryMode && (isLoadingFlows || flows.length > 0)) {
    return (
      <div className="flex items-center justify-center h-screen bg-background">
        <div className="text-center">
          <Loader2 className="h-8 w-8 animate-spin mx-auto mb-4" style={{ color: 'var(--warning)' }} />
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
      <div className="w-52 border-r bg-card flex flex-col">
        {/* Logo */}
        <div className="h-14 flex items-center px-4 border-b">
          <span className="font-bold text-lg" style={{ color: 'var(--warning)' }}>BotFacebook</span>
        </div>

        {/* Create New Flow Button */}
        <div className="p-3">
          <Button
            variant="orange"
            onClick={() => navigate(`/flows/new?botId=${botId}`)}
          >
            <Plus className="h-4 w-4 mr-2" />
            สร้างโฟลว์ใหม่
          </Button>
        </div>

        {/* Flow List */}
        <div className="flex-1 overflow-y-auto">
          {isLoadingFlows ? (
            <div className="flex justify-center py-4">
              <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
            </div>
          ) : flows.length === 0 ? (
            <p className="text-sm text-muted-foreground text-center py-4">ยังไม่มี Flow</p>
          ) : (
            <div className="space-y-1 px-2">
              {[...flows]
                .sort((a, b) => {
                  // Base flow always first
                  if (a.is_default && !b.is_default) return -1;
                  if (!a.is_default && b.is_default) return 1;
                  return 0;
                })
                .map((flow) => (
                <button
                  key={flow.id}
                  onClick={() => navigate(`/flows/${flow.id}/edit?botId=${botId}`)}
                  className={`w-full text-left px-3 py-2.5 rounded-lg text-sm transition-all duration-200 cursor-pointer
                    ${flow.is_default ? 'border-l-4 border-l-orange-500' : 'border-l-4 border-l-transparent'}
                    ${selectedFlowId === flow.id
                      ? 'bg-orange-500/10 text-orange-600 dark:text-orange-400 font-medium'
                      : 'hover:bg-muted'
                    }`}
                >
                  <div className="flex items-center gap-2">
                    {flow.is_default && (
                      <svg className="h-4 w-4 text-orange-500 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M5 4a2 2 0 012-2h6a2 2 0 012 2v14l-5-2.5L5 18V4z" />
                      </svg>
                    )}
                    <span className="truncate font-medium">{flow.name}</span>
                  </div>
                  {flow.is_default && (
                    <div className="mt-1 ml-6">
                      <span className="text-xs text-orange-500 font-medium">Base Flow</span>
                    </div>
                  )}
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Bottom Action Buttons */}
        <div className="p-3 border-t space-y-2">
          <Button variant="outline" size="sm" className="w-full justify-start" style={{ color: 'var(--warning)', borderColor: 'var(--warning)' }}>
            <Link2 className="h-4 w-4 mr-2" />
            Link ภายใน
          </Button>
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
            variant="outline"
            size="sm"
            className="w-full justify-start"
            onClick={() => navigate(`/bots/${botId}/settings`)}
          >
            <Bot className="h-4 w-4 mr-2" />
            ตั้งค่า Bot
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="w-full justify-start"
            style={{ color: 'var(--warning)' }}
            onClick={() => navigate('/bots')}
          >
            <ArrowLeft className="h-4 w-4 mr-2" />
            กลับไปหน้าการเชื่อมต่อ
          </Button>
        </div>
      </div>

      {/* Main Content Area - Split into Editor + Chat Emulator */}
      <div className="flex-1 flex overflow-hidden">
        {/* Editor Panel */}
        <div className="flex-1 flex flex-col overflow-hidden">
          {!showEditor ? (
            /* Empty State */
            <div className="flex-1 flex items-center justify-center">
              <p className="text-lg" style={{ color: 'var(--warning)' }}>เลือกหรือสร้างโฟลว์เพื่อเริ่มต้น</p>
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
                  variant="orange"
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


                {/* Agentic Mode Toggle */}
                <div className="flex items-start gap-4 p-4 border rounded-lg">
                  <Switch
                    checked={formData.agentic_mode}
                    onCheckedChange={(checked) => handleChange('agentic_mode', checked)}
                  />
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <span className="font-medium">Agentic Mode</span>
                      <Badge variant="warning">
                        AI ที่ฉลาดขึ้น
                      </Badge>
                    </div>
                    <p className="text-sm text-muted-foreground mt-1">
                      เปลี่ยน AI ธรรมดาให้เป็น AI Agent ที่สามารถค้นหาข้อมูล เรียกใช้ tools และตัดสินใจได้อย่างอัตโนมัติ
                    </p>

                    {formData.agentic_mode && (
                      <div className="mt-4 space-y-4">
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
                      </div>
                    )}
                  </div>
                </div>

                {/* Knowledge Bases (Multi-Select) */}
                <div className="space-y-3">
                  <div className="flex items-center gap-2">
                    <BookOpen className="h-4 w-4 text-muted-foreground" />
                    <span className="text-sm font-medium">ฐานความรู้ที่เชื่อมต่อ</span>
                    <Badge variant="outline" className="text-xs">
                      {formData.knowledge_bases?.length || 0} เลือก
                    </Badge>
                  </div>

                  {/* KB Selection Dropdown */}
                  <div className="border rounded-lg p-3 space-y-2 max-h-48 overflow-y-auto">
                    {isLoadingKBs ? (
                      <div className="flex items-center justify-center py-4">
                        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                      </div>
                    ) : allKnowledgeBases.length === 0 ? (
                      <p className="text-sm text-muted-foreground text-center py-4">
                        ยังไม่มีฐานความรู้ กรุณาสร้างฐานความรู้ก่อน
                      </p>
                    ) : (
                      allKnowledgeBases.map((kb) => {
                        const isSelected = formData.knowledge_bases?.some(k => k.id === kb.id);
                        return (
                          <label
                            key={kb.id}
                            className={`flex items-center gap-3 p-2 rounded-lg cursor-pointer transition-colors ${
                              isSelected ? 'bg-warning/10 border border-warning/30' : 'hover:bg-muted'
                            }`}
                          >
                            <input
                              type="checkbox"
                              checked={isSelected}
                              onChange={(e) => {
                                const currentKBs = formData.knowledge_bases || [];
                                if (e.target.checked) {
                                  // Add KB with default settings
                                  handleChange('knowledge_bases', [
                                    ...currentKBs,
                                    { id: kb.id, kb_top_k: 5, kb_similarity_threshold: 0.7 }
                                  ]);
                                } else {
                                  // Remove KB
                                  handleChange('knowledge_bases', currentKBs.filter(k => k.id !== kb.id));
                                }
                              }}
                              className="rounded border-border"
                            />
                            <div className="flex-1 min-w-0">
                              <div className="font-medium text-sm truncate">{kb.name}</div>
                              <div className="text-xs text-muted-foreground">
                                {kb.bot_name} • {kb.document_count} เอกสาร • {kb.chunk_count} chunks
                              </div>
                            </div>
                          </label>
                        );
                      })
                    )}
                  </div>

                  {/* Per-KB Settings */}
                  {formData.knowledge_bases && formData.knowledge_bases.length > 0 && (
                    <div className="space-y-4 mt-4">
                      <Label className="text-xs text-muted-foreground">ตั้งค่าแต่ละฐานความรู้</Label>
                      {formData.knowledge_bases.map((kbConfig, index) => {
                        const kbInfo = allKnowledgeBases.find(k => k.id === kbConfig.id);
                        return (
                          <div key={kbConfig.id} className="border rounded-lg p-3 space-y-3">
                            <div className="flex items-center justify-between">
                              <span className="text-sm font-medium">{kbInfo?.name || `KB #${kbConfig.id}`}</span>
                              <button
                                onClick={() => {
                                  const newKBs = [...(formData.knowledge_bases || [])];
                                  newKBs.splice(index, 1);
                                  handleChange('knowledge_bases', newKBs);
                                }}
                                className="text-muted-foreground hover:text-destructive"
                              >
                                <X className="h-4 w-4" />
                              </button>
                            </div>
                            <div className="grid grid-cols-2 gap-4">
                              <div>
                                <Label className="text-xs">Top K: {kbConfig.kb_top_k || 5}</Label>
                                <Slider
                                  value={[kbConfig.kb_top_k || 5]}
                                  onValueChange={([v]) => {
                                    const newKBs = [...(formData.knowledge_bases || [])];
                                    newKBs[index] = { ...newKBs[index], kb_top_k: v };
                                    handleChange('knowledge_bases', newKBs);
                                  }}
                                  min={1}
                                  max={20}
                                  step={1}
                                  className="mt-2"
                                />
                              </div>
                              <div>
                                <Label className="text-xs">Threshold: {kbConfig.kb_similarity_threshold || 0.7}</Label>
                                <Slider
                                  value={[kbConfig.kb_similarity_threshold || 0.7]}
                                  onValueChange={([v]) => {
                                    const newKBs = [...(formData.knowledge_bases || [])];
                                    newKBs[index] = { ...newKBs[index], kb_similarity_threshold: v };
                                    handleChange('knowledge_bases', newKBs);
                                  }}
                                  min={0.1}
                                  max={1}
                                  step={0.05}
                                  className="mt-2"
                                />
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>

                {/* System Prompt */}
                <div className="space-y-3 border rounded-lg overflow-hidden">
                  <div className="px-4 pt-4 pb-2">
                    <div className="flex items-center gap-2 mb-3">
                      <span className="text-sm font-medium">
                        เขียนคำสั่งให้ AI สร้างการตอบกลับ - คุณสามารถดูตัวอย่างการเขียนคำสั่งได้ใน{' '}
                        <a href="#" className="hover:underline" style={{ color: 'var(--warning)' }}>
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
                      onFullscreen={() => {
                        // Placeholder for fullscreen functionality
                        toast({ title: 'Feature coming soon', description: 'Fullscreen mode will be available in next update' });
                      }}
                      isPreviewMode={isSystemPromptPreview}
                    />
                  </div>
                  {!isSystemPromptPreview ? (
                    <>
                      <Textarea
                        ref={systemPromptRef}
                        placeholder="คุณคือผู้ช่วยที่เป็นมิตร..."
                        className="min-h-[300px] font-mono text-sm border-0 rounded-none focus-visible:ring-0"
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
                    onCheckedChange={setAgenticSecondAIEnabled}
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

              {/* Issue #56: Plugins */}
              <div className="border rounded-lg p-6">
                <div className="flex items-center justify-between mb-4">
                  <div>
                    <Label className="font-medium flex items-center gap-2">
                      <Zap className="h-4 w-4" />
                      Plugins
                    </Label>
                    <p className="text-sm text-muted-foreground mt-1">
                      เพิ่มฟังก์ชันเพิ่มเติมให้ AI ผ่าน plugins
                    </p>
                  </div>
                </div>

                {plugins.length === 0 ? (
                  <div className="border-2 border-dashed rounded-lg p-6 text-center">
                    <Plus className="h-8 w-8 text-muted-foreground mx-auto mb-2" />
                    <p className="text-sm text-muted-foreground mb-3">ยังไม่มี plugins</p>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        const newPlugin = {
                          id: generateId(),
                          name: `Plugin ${plugins.length + 1}`,
                        };
                        setPlugins([...plugins, newPlugin]);
                      }}
                    >
                      <Plus className="h-4 w-4 mr-2" />
                      เพิ่ม Plugin
                    </Button>
                  </div>
                ) : (
                  <div className="space-y-2">
                    {plugins.map((plugin) => (
                      <div
                        key={plugin.id}
                        className="flex items-center justify-between p-3 border rounded-lg bg-muted/30"
                      >
                        <span className="text-sm font-medium">{plugin.name}</span>
                        <button
                          onClick={() =>
                            setPlugins(plugins.filter((p) => p.id !== plugin.id))
                          }
                          className="text-destructive hover:text-destructive/80"
                        >
                          <Trash2 className="h-4 w-4" />
                        </button>
                      </div>
                    ))}
                    <Button
                      variant="outline"
                      size="sm"
                      className="w-full mt-3"
                      onClick={() => {
                        const newPlugin = {
                          id: generateId(),
                          name: `Plugin ${plugins.length + 1}`,
                        };
                        setPlugins([...plugins, newPlugin]);
                      }}
                    >
                      <Plus className="h-4 w-4 mr-2" />
                      เพิ่ม Plugin
                    </Button>
                  </div>
                )}
              </div>

            </div>
          </div>
        )}
        </div>

        {/* Chat Emulator - Right Panel (Full Height) */}
        <div className="w-96 border-l bg-card flex flex-col">
          {/* Header */}
          <div
            className="flex items-center justify-between px-4 py-3 text-white border-b"
            style={{ backgroundColor: 'var(--warning)' }}
          >
            <div className="flex items-center gap-2">
              <MessageCircle className="h-5 w-5" />
              <span className="font-semibold">แชทจำลอง</span>
            </div>
            <div className="flex items-center gap-2">
              {/* Thinking Toggle */}
              <button
                onClick={() => setEnableThinking(!enableThinking)}
                className={`flex items-center gap-1 px-2 py-1 rounded text-xs transition-colors ${
                  enableThinking ? 'bg-purple-500/30 text-white' : 'bg-white/10 text-white/70'
                }`}
                title={enableThinking ? 'Thinking Mode เปิด' : 'Thinking Mode ปิด'}
              >
                <Brain className="h-3 w-3" />
                <span>Think</span>
              </button>
              {/* Clear Button */}
              <Button
                size="sm"
                variant="ghost"
                className="h-8 w-8 p-0 text-white hover:bg-white/20"
                onClick={clearMessages}
                title="ล้างแชท"
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          </div>

          {/* Messages Area */}
          <div className="flex-1 overflow-y-auto p-4 space-y-3">
            {chatMessages.length === 0 ? (
              <div className="flex flex-col items-center justify-center h-full text-center">
                <MessageCircle className="h-12 w-12 text-muted-foreground/30 mb-3" />
                <p className="text-sm text-muted-foreground">
                  ทดสอบการตอบกลับของ AI
                </p>
                <p className="text-xs text-muted-foreground/70 mt-1">
                  พิมพ์ข้อความด้านล่างเพื่อเริ่มต้น
                </p>
                {enableThinking && (
                  <p className="text-xs text-purple-500 mt-2">
                    🧠 Thinking Mode เปิดอยู่
                  </p>
                )}
              </div>
            ) : (
              chatMessages.map((msg) => (
                <div
                  key={msg.id}
                  className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                >
                  <div className={`max-w-[85%] ${msg.role === 'assistant' ? 'w-full' : ''}`}>
                    {/* Show thinking display for assistant messages */}
                    {msg.role === 'assistant' && (msg.thinking || msg.isStreaming) && (
                      <ThinkingDisplay
                        thinking={msg.thinking || ''}
                        isStreaming={msg.isStreaming}
                      />
                    )}
                    <div
                      className={`rounded-2xl px-4 py-2.5 text-sm ${
                        msg.role === 'user'
                          ? 'text-white rounded-br-md'
                          : 'bg-muted rounded-bl-md'
                      }`}
                      style={msg.role === 'user' ? { backgroundColor: 'var(--warning)' } : {}}
                    >
                      {msg.content}
                      {/* Show streaming cursor */}
                      {msg.role === 'assistant' && msg.isStreaming && !msg.content && (
                        <span className="flex items-center gap-1 text-muted-foreground">
                          <Loader2 className="h-3 w-3 animate-spin" />
                          <span>กำลังตอบ...</span>
                        </span>
                      )}
                      {msg.role === 'assistant' && msg.isStreaming && msg.content && (
                        <span className="animate-pulse text-warning">|</span>
                      )}
                    </div>
                  </div>
                </div>
              ))
            )}
            <div ref={chatEndRef} />
          </div>

          {/* Input Area */}
          <div className="border-t p-4 space-y-3 bg-background/50">
            <div className="flex gap-2">
              <input
                type="text"
                placeholder={isStreaming ? 'กำลังประมวลผล...' : 'พิมพ์ข้อความ...'}
                className="flex-1 px-4 py-2.5 rounded-full border bg-background text-sm focus:outline-none focus:ring-2 focus:ring-warning/50"
                value={chatInput}
                onChange={(e) => setChatInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey && !isStreaming) {
                    e.preventDefault();
                    handleSendMessage();
                  }
                }}
                disabled={isStreaming}
              />
              {isStreaming ? (
                <Button
                  size="icon"
                  onClick={cancelStream}
                  variant="destructive"
                  className="rounded-full h-10 w-10"
                  title="หยุดการตอบ"
                >
                  <Square className="h-4 w-4" />
                </Button>
              ) : (
                <Button
                  size="icon"
                  onClick={handleSendMessage}
                  disabled={!chatInput.trim()}
                  variant="orange"
                  className="rounded-full h-10 w-10"
                >
                  <Send className="h-4 w-4" />
                </Button>
              )}
            </div>
            <div className="flex gap-2 justify-center">
              <Button
                size="sm"
                variant="ghost"
                className="h-8 px-3 text-muted-foreground cursor-not-allowed"
                title="Attach File (Coming Soon)"
                disabled
              >
                <Paperclip className="h-4 w-4 mr-1" />
                <span className="text-xs">ไฟล์</span>
              </Button>
              <Button
                size="sm"
                variant="ghost"
                className="h-8 px-3 text-muted-foreground cursor-not-allowed"
                title="Attach Image (Coming Soon)"
                disabled
              >
                <ImageIcon className="h-4 w-4 mr-1" />
                <span className="text-xs">รูปภาพ</span>
              </Button>
            </div>
          </div>
        </div>
      </div>

      {/* Unsaved changes toast */}
      {hasChanges && (
        <div className="fixed bottom-4 left-1/2 -translate-x-1/2 bg-background border rounded-lg shadow-lg px-4 py-3 flex items-center gap-4 z-50">
          <span className="text-sm text-muted-foreground">มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก</span>
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
                    model: existingFlow.model,
                    fallback_model: existingFlow.fallback_model || '',
                    decision_model: existingFlow.decision_model || '',
                    fallback_decision_model: existingFlow.fallback_decision_model || '',
                    temperature: existingFlow.temperature,
                    max_tokens: existingFlow.max_tokens,
                    agentic_mode: existingFlow.agentic_mode,
                    max_tool_calls: existingFlow.max_tool_calls,
                    enabled_tools: existingFlow.enabled_tools || [],
                    knowledge_bases: kbData,
                    language: existingFlow.language,
                    is_default: existingFlow.is_default,
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
    </div>
  );
}
