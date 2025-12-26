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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { useFlow, useCreateFlow, useUpdateFlow, useFlowTemplates, useFlowOperations } from '@/hooks/useFlows';
import { useKnowledgeBase } from '@/hooks/useKnowledgeBase';
import { useToast } from '@/hooks/use-toast';
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
} from 'lucide-react';
import type { CreateFlowData, FlowTemplate } from '@/types/api';

// Helper to generate unique IDs
function generateId() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
}

// Initial form data constant
const INITIAL_FORM_DATA: CreateFlowData = {
  name: '',
  description: '',
  system_prompt: '',
  model: 'claude-3-5-sonnet-20241022',
  temperature: 0.7,
  max_tokens: 2048,
  agentic_mode: false,
  max_tool_calls: 10,
  enabled_tools: [],
  knowledge_base_id: null,
  kb_top_k: 5,
  kb_similarity_threshold: 0.7,
  language: 'th',
  is_default: false,
};

// Type guard for language validation
function isValidLanguage(value: string): value is 'th' | 'en' | 'zh' | 'ja' | 'ko' {
  return ['th', 'en', 'zh', 'ja', 'ko'].includes(value);
}

const AVAILABLE_MODELS = [
  { id: 'claude-3-5-sonnet-20241022', name: 'Claude 3.5 Sonnet' },
  { id: 'claude-3-5-haiku-20241022', name: 'Claude 3.5 Haiku' },
  { id: 'gpt-4o', name: 'GPT-4o' },
  { id: 'gpt-4o-mini', name: 'GPT-4o Mini' },
];

const LANGUAGES = [
  { id: 'th', name: 'ไทย' },
  { id: 'en', name: 'English' },
  { id: 'zh', name: '中文' },
  { id: 'ja', name: '日本語' },
  { id: 'ko', name: '한국어' },
];

export function FlowEditorPage() {
  const { flowId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const location = useLocation();
  const { toast } = useToast();

  const botIdParam = searchParams.get('botId');
  const parsedBotId = botIdParam ? parseInt(botIdParam, 10) : null;
  const botId = parsedBotId && !isNaN(parsedBotId) ? parsedBotId : null;

  // Detect if we're in editor entry mode (/flows/editor - no flowId)
  const isEditorEntryMode = location.pathname === '/flows/editor';

  // Get flows list
  const { flows, isLoading: isLoadingFlows } = useFlowOperations(botId);

  // Current flow being edited
  const parsedFlowId = flowId && flowId !== 'new' ? parseInt(flowId, 10) : null;
  const selectedFlowId = parsedFlowId && !isNaN(parsedFlowId) ? parsedFlowId : null;
  const isCreatingNew = flowId === 'new';

  // Fetch existing flow if editing
  const { data: existingFlow, isLoading: isLoadingFlow } = useFlow(botId, selectedFlowId);

  // Fetch knowledge base
  const { data: knowledgeBase } = useKnowledgeBase(botId);

  // Fetch templates
  const { data: templates } = useFlowTemplates();

  // Mutations
  const createMutation = useCreateFlow(botId);
  const updateMutation = useUpdateFlow(botId, selectedFlowId);

  // Form state
  const [formData, setFormData] = useState<CreateFlowData>(INITIAL_FORM_DATA);
  const [hasChanges, setHasChanges] = useState(false);

  // Chat emulator state
  const [chatMessages, setChatMessages] = useState<Array<{ id: string; role: 'user' | 'assistant'; content: string }>>([]);
  const [chatInput, setChatInput] = useState('');
  const [isChatOpen, setIsChatOpen] = useState(true);
  const chatEndRef = useRef<HTMLDivElement>(null);
  const timeoutsRef = useRef<ReturnType<typeof setTimeout>[]>([]);

  // Collapsible sections
  const [isBaseFlowInfoOpen, setIsBaseFlowInfoOpen] = useState(false);

  // Auto-redirect in editor entry mode
  useEffect(() => {
    if (isEditorEntryMode && !isLoadingFlows && botId) {
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
  }, [isEditorEntryMode, isLoadingFlows, flows, botId, navigate]);

  // Load existing flow data
  useEffect(() => {
    if (existingFlow) {
      setFormData({
        name: existingFlow.name,
        description: existingFlow.description || '',
        system_prompt: existingFlow.system_prompt,
        model: existingFlow.model,
        temperature: existingFlow.temperature,
        max_tokens: existingFlow.max_tokens,
        agentic_mode: existingFlow.agentic_mode,
        max_tool_calls: existingFlow.max_tool_calls,
        enabled_tools: existingFlow.enabled_tools || [],
        knowledge_base_id: existingFlow.knowledge_base_id,
        kb_top_k: existingFlow.kb_top_k,
        kb_similarity_threshold: existingFlow.kb_similarity_threshold,
        language: existingFlow.language,
        is_default: existingFlow.is_default,
      });
      setHasChanges(false);
    }
  }, [existingFlow]);

  // Cleanup timeouts on unmount
  useEffect(() => {
    return () => {
      timeoutsRef.current.forEach(clearTimeout);
      timeoutsRef.current = [];
    };
  }, []);

  // Scroll to bottom of chat
  useEffect(() => {
    chatEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [chatMessages]);

  // Handle form change
  const handleChange = <K extends keyof CreateFlowData>(field: K, value: CreateFlowData[K]) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
    setHasChanges(true);
  };

  // Apply template
  const handleApplyTemplate = (template: FlowTemplate) => {
    const validLanguage = isValidLanguage(template.language)
      ? template.language
      : 'th';

    setFormData((prev) => ({
      ...prev,
      name: prev.name || template.name,
      system_prompt: template.system_prompt,
      temperature: template.temperature,
      language: validLanguage,
    }));
    setHasChanges(true);
    toast({
      title: 'ใช้ Template แล้ว',
      description: `นำ "${template.name}" มาใช้เรียบร้อย`,
    });
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

  // Handle chat emulator
  const handleSendMessage = () => {
    if (!chatInput.trim()) return;

    const newMessage = {
      id: generateId(),
      role: 'user' as const,
      content: chatInput,
    };

    setChatMessages((prev) => [...prev, newMessage]);
    setChatInput('');

    // Simulate assistant response
    const timeoutId = setTimeout(() => {
      setChatMessages((prev) => [
        ...prev,
        {
          id: generateId(),
          role: 'assistant',
          content: 'นี่คือการตอบทดสอบจากบอท (Emulator)',
        },
      ]);
    }, 500);

    timeoutsRef.current.push(timeoutId);
  };

  // Show loading during editor entry mode redirect
  if (isEditorEntryMode && (isLoadingFlows || flows.length > 0)) {
    return (
      <div className="flex items-center justify-center h-screen bg-background">
        <div className="text-center">
          <Loader2 className="h-8 w-8 animate-spin text-amber-500 mx-auto mb-4" />
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
      {/* Left Sidebar - Flow List (dabby.io style) */}
      <div className="w-52 border-r bg-card flex flex-col">
        {/* Logo */}
        <div className="h-14 flex items-center px-4 border-b">
          <span className="font-bold text-lg text-amber-500">BotFacebook</span>
        </div>

        {/* Create New Flow Button */}
        <div className="p-3">
          <Button
            className="w-full bg-amber-500 hover:bg-amber-600 text-white"
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
              {flows.map((flow) => (
                <button
                  key={flow.id}
                  onClick={() => navigate(`/flows/${flow.id}/edit?botId=${botId}`)}
                  className={`w-full text-left px-3 py-2 rounded-lg text-sm transition-colors ${
                    selectedFlowId === flow.id
                      ? 'bg-amber-100 text-amber-900 font-medium'
                      : 'hover:bg-muted'
                  }`}
                >
                  <div className="flex items-center gap-2">
                    {flow.is_default && <span>📌</span>}
                    <span className="truncate">{flow.name}</span>
                  </div>
                  {flow.is_default && (
                    <div className="flex items-center gap-1 mt-1">
                      <span className="text-amber-600 text-xs">★ Flow เริ่มต้น</span>
                    </div>
                  )}
                </button>
              ))}
            </div>
          )}
        </div>

        {/* Bottom Action Buttons */}
        <div className="p-3 border-t space-y-2">
          <Button variant="outline" size="sm" className="w-full justify-start text-amber-600 border-amber-300">
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
            className="w-full justify-start text-amber-600"
            onClick={() => navigate('/bots')}
          >
            <ArrowLeft className="h-4 w-4 mr-2" />
            กลับไปหน้าการเชื่อมต่อ
          </Button>
        </div>
      </div>

      {/* Main Content Area */}
      <div className="flex-1 flex flex-col overflow-hidden">
        {!showEditor ? (
          /* Empty State */
          <div className="flex-1 flex items-center justify-center">
            <p className="text-amber-500 text-lg">เลือกหรือสร้างโฟลว์เพื่อเริ่มต้น</p>
          </div>
        ) : isLoadingFlow ? (
          <div className="flex-1 flex items-center justify-center">
            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
          </div>
        ) : (
          /* Editor Content - Single Column (dabby.io style) */
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
                  className="bg-amber-500 hover:bg-amber-600"
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
                      <Badge variant="secondary" className="bg-amber-100 text-amber-700">
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

                {/* Knowledge Base */}
                <div className="space-y-3">
                  <div className="flex items-center gap-2">
                    <BookOpen className="h-4 w-4 text-muted-foreground" />
                    <span className="text-sm font-medium">ฐานความรู้ที่เชื่อมต่อ</span>
                  </div>
                  {knowledgeBase ? (
                    <div className="flex items-center gap-2">
                      <Badge variant="secondary" className="gap-2">
                        {knowledgeBase.name}
                        <button
                          onClick={() => handleChange('knowledge_base_id', null)}
                          className="hover:text-destructive"
                        >
                          <X className="h-3 w-3" />
                        </button>
                      </Badge>
                    </div>
                  ) : (
                    <Input placeholder="ค้นหาฐานความรู้..." className="max-w-md" />
                  )}

                  {formData.knowledge_base_id && (
                    <div className="grid grid-cols-2 gap-4 mt-4">
                      <div>
                        <Label className="text-xs">Top K Results: {formData.kb_top_k}</Label>
                        <Slider
                          value={[formData.kb_top_k || 5]}
                          onValueChange={([v]) => handleChange('kb_top_k', v)}
                          min={1}
                          max={20}
                          step={1}
                          className="mt-2"
                        />
                      </div>
                      <div>
                        <Label className="text-xs">Similarity Threshold: {formData.kb_similarity_threshold}</Label>
                        <Slider
                          value={[formData.kb_similarity_threshold || 0.7]}
                          onValueChange={([v]) => handleChange('kb_similarity_threshold', v)}
                          min={0.1}
                          max={1}
                          step={0.05}
                          className="mt-2"
                        />
                      </div>
                    </div>
                  )}
                </div>

                {/* System Prompt */}
                <div className="space-y-3">
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium">
                      เขียนคำสั่งให้ AI สร้างการตอบกลับ - คุณสามารถดูตัวอย่างการเขียนคำสั่งได้ใน{' '}
                      <a href="#" className="text-amber-600 hover:underline">
                        คู่มือการใช้งาน & Prompts Library
                      </a>
                    </span>
                  </div>
                  <Textarea
                    placeholder="คุณคือผู้ช่วยที่เป็นมิตร..."
                    className="min-h-[300px] font-mono text-sm"
                    value={formData.system_prompt}
                    onChange={(e) => handleChange('system_prompt', e.target.value)}
                  />
                  <div className="flex justify-end gap-4 text-xs text-muted-foreground">
                    <span>lines: {formData.system_prompt.split('\n').length}</span>
                    <span>words: {formData.system_prompt.split(/\s+/).filter(Boolean).length}</span>
                  </div>
                </div>

                {/* Model Settings */}
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label>AI Model</Label>
                    <Select value={formData.model} onValueChange={(v) => handleChange('model', v)}>
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {AVAILABLE_MODELS.map((model) => (
                          <SelectItem key={model.id} value={model.id}>
                            {model.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="space-y-2">
                    <Label>ภาษา</Label>
                    <Select
                      value={formData.language}
                      onValueChange={(v) => {
                        if (isValidLanguage(v)) handleChange('language', v);
                      }}
                    >
                      <SelectTrigger>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {LANGUAGES.map((lang) => (
                          <SelectItem key={lang.id} value={lang.id}>
                            {lang.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
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

              {/* Templates Section */}
              {templates && templates.length > 0 && (
                <div className="border rounded-lg p-6">
                  <h3 className="font-medium mb-4">Templates</h3>
                  <div className="grid grid-cols-2 gap-3">
                    {templates.slice(0, 4).map((template) => (
                      <button
                        key={template.id}
                        onClick={() => handleApplyTemplate(template)}
                        className="text-left p-3 border rounded-lg hover:border-amber-500 transition-colors"
                      >
                        <p className="font-medium text-sm">{template.name}</p>
                        <p className="text-xs text-muted-foreground mt-1">{template.description}</p>
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      {/* Chat Emulator - Floating Panel (dabby.io style) */}
      <div
        className={`fixed bottom-4 right-4 w-80 bg-card border rounded-lg shadow-xl transition-all duration-300 ${
          isChatOpen ? 'h-96' : 'h-auto'
        }`}
      >
        {/* Header */}
        <button
          onClick={() => setIsChatOpen(!isChatOpen)}
          className="w-full flex items-center justify-between px-4 py-3 bg-amber-500 text-white rounded-t-lg"
        >
          <div className="flex items-center gap-2">
            <MessageCircle className="h-4 w-4" />
            <span className="font-medium text-sm">แชทจำลอง</span>
          </div>
          {isChatOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronUp className="h-4 w-4" />}
        </button>

        {isChatOpen && (
          <>
            {/* Messages */}
            <div className="h-56 overflow-y-auto p-3 space-y-2">
              {chatMessages.length === 0 && (
                <p className="text-xs text-muted-foreground text-center py-8">
                  เริ่มพิมพ์เพื่อทดสอบ
                </p>
              )}
              {chatMessages.map((msg) => (
                <div
                  key={msg.id}
                  className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                >
                  <div
                    className={`max-w-[80%] rounded-lg px-3 py-2 text-sm ${
                      msg.role === 'user'
                        ? 'bg-amber-500 text-white'
                        : 'bg-muted'
                    }`}
                  >
                    {msg.content}
                  </div>
                </div>
              ))}
              <div ref={chatEndRef} />
            </div>

            {/* Input */}
            <div className="border-t p-3 flex gap-2">
              <input
                type="text"
                placeholder="พิมพ์ข้อความ..."
                className="flex-1 px-3 py-2 rounded-lg border bg-background text-sm"
                value={chatInput}
                onChange={(e) => setChatInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    handleSendMessage();
                  }
                }}
              />
              <Button
                size="sm"
                onClick={handleSendMessage}
                disabled={!chatInput.trim()}
                className="bg-amber-500 hover:bg-amber-600"
              >
                <Send className="h-4 w-4" />
              </Button>
            </div>
          </>
        )}
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
                  setFormData({
                    name: existingFlow.name,
                    description: existingFlow.description || '',
                    system_prompt: existingFlow.system_prompt,
                    model: existingFlow.model,
                    temperature: existingFlow.temperature,
                    max_tokens: existingFlow.max_tokens,
                    agentic_mode: existingFlow.agentic_mode,
                    max_tool_calls: existingFlow.max_tool_calls,
                    enabled_tools: existingFlow.enabled_tools || [],
                    knowledge_base_id: existingFlow.knowledge_base_id,
                    kb_top_k: existingFlow.kb_top_k,
                    kb_similarity_threshold: existingFlow.kb_similarity_threshold,
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
