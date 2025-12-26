import { useState, useEffect, useRef } from 'react';
import { useParams, useSearchParams, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import { useFlow, useCreateFlow, useUpdateFlow, useFlowTemplates, useFlowOperations } from '@/hooks/useFlows';
import { useKnowledgeBase, useBots } from '@/hooks/useKnowledgeBase';
import { useToast } from '@/hooks/use-toast';
import {
  Loader2,
  ArrowLeft,
  Save,
  Sparkles,
  BookOpen,
  Settings2,
  FileText,
  Zap,
  Plus,
  MessageCircle,
  Send,
} from 'lucide-react';
import type { CreateFlowData, FlowTemplate } from '@/types/api';

// Helper to generate unique IDs with fallback for older browsers
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
  { id: 'th', name: 'Thai' },
  { id: 'en', name: 'English' },
  { id: 'zh', name: 'Chinese' },
  { id: 'ja', name: 'Japanese' },
  { id: 'ko', name: 'Korean' },
];

export function FlowEditorPage() {
  const { flowId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { toast } = useToast();

  const botIdParam = searchParams.get('botId');
  const botId = botIdParam ? parseInt(botIdParam, 10) : null;

  // Get bots for selection
  const { data: botsResponse } = useBots();
  const bots = botsResponse?.data || [];
  const selectedBot = bots.find((b) => b.id === botId);

  // Get flows list
  const { flows, isLoading: isLoadingFlows } = useFlowOperations(botId);

  // Current flow being edited
  const selectedFlowId = flowId && flowId !== 'new' ? parseInt(flowId, 10) : null;
  const isCreatingNew = flowId === 'new' || !flowId;

  // Fetch existing flow if editing
  const { data: existingFlow, isLoading: isLoadingFlow } = useFlow(botId, selectedFlowId);

  // Fetch knowledge base
  const { data: knowledgeBase } = useKnowledgeBase(botId);

  // Fetch templates
  const { data: templates, isLoading: isTemplatesLoading } = useFlowTemplates();

  // Mutations
  const createMutation = useCreateFlow(botId);
  const updateMutation = useUpdateFlow(botId, selectedFlowId);

  // Form state
  const [formData, setFormData] = useState<CreateFlowData>(INITIAL_FORM_DATA);

  const [templateDialogOpen, setTemplateDialogOpen] = useState(false);
  const [hasChanges, setHasChanges] = useState(false);
  const [chatMessages, setChatMessages] = useState<Array<{ id: string; role: 'user' | 'assistant'; content: string }>>([]);
  const [chatInput, setChatInput] = useState('');
  const chatEndRef = useRef<HTMLDivElement>(null);
  const timeoutsRef = useRef<ReturnType<typeof setTimeout>[]>([]);

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
    }
  }, [existingFlow]);

  // Cleanup timeouts on unmount
  useEffect(() => {
    return () => {
      timeoutsRef.current.forEach(clearTimeout);
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
    setFormData((prev) => ({
      ...prev,
      name: prev.name || template.name,
      system_prompt: template.system_prompt,
      temperature: template.temperature,
      language: template.language as 'th' | 'en' | 'zh' | 'ja' | 'ko',
    }));
    setHasChanges(true);
    setTemplateDialogOpen(false);
    toast({
      title: 'Template applied',
      description: `"${template.name}" template has been applied.`,
    });
  };

  // Handle save
  const handleSave = async () => {
    if (!botId) {
      toast({
        title: 'Error',
        description: 'Bot ID is required',
        variant: 'destructive',
      });
      return;
    }

    if (!formData.name.trim()) {
      toast({
        title: 'Validation Error',
        description: 'Flow name is required',
        variant: 'destructive',
      });
      return;
    }

    if (!formData.system_prompt.trim()) {
      toast({
        title: 'Validation Error',
        description: 'System prompt is required',
        variant: 'destructive',
      });
      return;
    }

    try {
      if (selectedFlowId) {
        await updateMutation.mutateAsync(formData);
        toast({
          title: 'Flow updated',
          description: 'Your changes have been saved.',
        });
      } else {
        await createMutation.mutateAsync(formData);
        toast({
          title: 'Flow created',
          description: 'Your new flow has been created.',
        });
      }
      setHasChanges(false);
    } catch (err) {
      toast({
        title: 'Error',
        description: err instanceof Error ? err.message : 'Failed to save flow',
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
          content: 'นี่คือการตอบสอบจากบอท (Emulator)',
        },
      ]);
    }, 500);

    // Track timeout for cleanup
    timeoutsRef.current.push(timeoutId);
  };

  // No bot selected
  if (!botId) {
    return (
      <div className="flex items-center justify-center h-screen bg-background">
        <div className="text-center">
          <p className="text-destructive mb-4">No bot selected. Please select a bot first.</p>
          <Button onClick={() => navigate('/bots')}>Go to Bots</Button>
        </div>
      </div>
    );
  }

  const isSaving = createMutation.isPending || updateMutation.isPending;

  return (
    <div className="flex h-screen bg-background">
      {/* Main content area (full width, no sidebar) */}
      <div className="flex flex-col flex-1 overflow-hidden">
        {/* Top Bar - dabby.io style */}
        <div className="border-b bg-card">
          {/* Logo and title row */}
          <div className="flex items-center justify-between h-16 px-6 border-b">
            <div className="flex items-center gap-4">
              <Button variant="ghost" size="icon" onClick={() => navigate('/bots')}>
                <ArrowLeft className="h-4 w-4" />
              </Button>
              <h1 className="text-xl font-semibold">Flow Editor</h1>
              {selectedBot && (
                <Badge variant="outline" className="font-normal">
                  {selectedBot.name}
                </Badge>
              )}
            </div>

            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm">
                <Plus className="h-4 w-4 mr-2" />
                Link ภายใน
              </Button>
              <Button variant="outline" size="sm">ตั้งค่า Bot</Button>
              <Button variant="outline" size="sm" onClick={() => navigate('/bots')}>
                กลับไปหน้าการเชื่อมต่อ
              </Button>
            </div>
          </div>

          {/* Flow list horizontal scroll - dabby.io style */}
          <div className="flex items-center gap-2 h-14 px-6 overflow-x-auto">
            <Button
              variant={isCreatingNew && !selectedFlowId ? 'default' : 'ghost'}
              size="sm"
              onClick={() => navigate(`/flows/new?botId=${botId}`)}
              className="shrink-0"
            >
              <Plus className="h-4 w-4 mr-1" />
              สร้างโฟลว์ใหม่
            </Button>

            <div className="w-px h-6 bg-border" />

            {/* Flow list */}
            {isLoadingFlows ? (
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            ) : flows.length === 0 ? (
              <p className="text-sm text-muted-foreground">ยังไม่มี Flow</p>
            ) : (
              flows.map((flow) => (
                <Button
                  key={flow.id}
                  variant={selectedFlowId === flow.id ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => navigate(`/flows/${flow.id}/edit?botId=${botId}`)}
                  className="shrink-0"
                >
                  {flow.is_default && '📌 '}
                  {flow.name}
                </Button>
              ))
            )}
          </div>
        </div>

        {/* Main editor area */}
        <div className="flex flex-1 overflow-hidden">
          <div className="flex-1 overflow-auto p-6">
            {isLoadingFlow && selectedFlowId ? (
              <div className="flex items-center justify-center h-64">
                <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
              </div>
            ) : !selectedFlowId && isCreatingNew ? (
              <div className="text-center py-12">
                <p className="text-muted-foreground mb-4">เลือกหรือสร้างโฟลว์เพื่อเริ่มต้น</p>
              </div>
            ) : (
              <div className="space-y-6 max-w-4xl">
                {/* Header */}
                <div className="flex items-center justify-between">
                  <div>
                    <h2 className="text-2xl font-bold tracking-tight">
                      {selectedFlowId ? 'แก้ไข Flow' : 'สร้าง Flow ใหม่'}
                    </h2>
                  </div>
                  <div className="flex items-center gap-2">
                    <Dialog open={templateDialogOpen} onOpenChange={setTemplateDialogOpen}>
                      <DialogTrigger asChild>
                        <Button variant="outline">
                          <Sparkles className="h-4 w-4 mr-2" />
                          Templates
                        </Button>
                      </DialogTrigger>
                      <DialogContent className="max-w-2xl">
                        <DialogHeader>
                          <DialogTitle>Choose a Template</DialogTitle>
                          <DialogDescription>
                            Start with a pre-built template to quickly configure your bot's behavior
                          </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 py-4">
                          {isTemplatesLoading ? (
                            <div className="flex items-center justify-center py-8">
                              <Loader2 className="h-6 w-6 animate-spin" />
                            </div>
                          ) : templates && templates.length > 0 ? (
                            templates.map((template) => (
                              <Card
                                key={template.id}
                                className="cursor-pointer hover:border-primary transition-colors"
                                onClick={() => handleApplyTemplate(template)}
                              >
                                <CardHeader className="pb-2">
                                  <div className="flex items-center justify-between">
                                    <CardTitle className="text-base">{template.name}</CardTitle>
                                    <Badge variant="outline">Temp: {template.temperature}</Badge>
                                  </div>
                                  <CardDescription>{template.description}</CardDescription>
                                </CardHeader>
                              </Card>
                            ))
                          ) : (
                            <p className="text-center text-muted-foreground py-8">No templates available</p>
                          )}
                        </div>
                      </DialogContent>
                    </Dialog>

                    <Button onClick={handleSave} disabled={isSaving || !hasChanges} className="bg-amber-500 hover:bg-amber-600">
                      {isSaving ? (
                        <>
                          <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                          บันทึก...
                        </>
                      ) : (
                        <>
                          <Save className="h-4 w-4 mr-2" />
                          บันทึก Flow
                        </>
                      )}
                    </Button>
                  </div>
                </div>

                {/* Tabs content */}
                <Tabs defaultValue="prompt" className="space-y-4">
                  <TabsList>
                    <TabsTrigger value="prompt">
                      <FileText className="h-4 w-4 mr-2" />
                      Prompt
                    </TabsTrigger>
                    <TabsTrigger value="model">
                      <Settings2 className="h-4 w-4 mr-2" />
                      Model Settings
                    </TabsTrigger>
                    <TabsTrigger value="knowledge">
                      <BookOpen className="h-4 w-4 mr-2" />
                      Knowledge Base
                    </TabsTrigger>
                    <TabsTrigger value="advanced">
                      <Zap className="h-4 w-4 mr-2" />
                      Advanced
                    </TabsTrigger>
                  </TabsList>

                  {/* Prompt Tab */}
                  <TabsContent value="prompt" className="space-y-4">
                    <Card>
                      <CardHeader>
                        <CardTitle>Flow Details</CardTitle>
                        <CardDescription>Basic information about this flow</CardDescription>
                      </CardHeader>
                      <CardContent className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                          <div className="space-y-2">
                            <Label htmlFor="name">Flow Name *</Label>
                            <Input
                              id="name"
                              placeholder="e.g., Customer Support"
                              value={formData.name}
                              onChange={(e) => handleChange('name', e.target.value)}
                            />
                          </div>
                          <div className="space-y-2">
                            <Label htmlFor="language">Language</Label>
                            <Select
                              value={formData.language}
                              onValueChange={(v) => {
                                if (isValidLanguage(v)) {
                                  handleChange('language', v);
                                }
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
                        <div className="space-y-2">
                          <Label htmlFor="description">Description</Label>
                          <Input
                            id="description"
                            placeholder="Brief description of this flow's purpose"
                            value={formData.description || ''}
                            onChange={(e) => handleChange('description', e.target.value)}
                          />
                        </div>
                        <div className="flex items-center space-x-2">
                          <Switch
                            id="is_default"
                            checked={formData.is_default}
                            onCheckedChange={(checked) => handleChange('is_default', checked)}
                          />
                          <Label htmlFor="is_default">Set as default flow</Label>
                        </div>
                      </CardContent>
                    </Card>

                    <Card>
                      <CardHeader>
                        <CardTitle>System Prompt *</CardTitle>
                        <CardDescription>
                          Define the bot's personality, behavior, and instructions
                        </CardDescription>
                      </CardHeader>
                      <CardContent>
                        <Textarea
                          placeholder="You are a helpful assistant..."
                          className="min-h-[300px] font-mono text-sm"
                          value={formData.system_prompt}
                          onChange={(e) => handleChange('system_prompt', e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground mt-2">
                          {formData.system_prompt.length} characters
                        </p>
                      </CardContent>
                    </Card>
                  </TabsContent>

                  {/* Model Settings Tab */}
                  <TabsContent value="model" className="space-y-4">
                    <Card>
                      <CardHeader>
                        <CardTitle>Model Configuration</CardTitle>
                        <CardDescription>Choose the AI model and its parameters</CardDescription>
                      </CardHeader>
                      <CardContent className="space-y-6">
                        <div className="space-y-2">
                          <Label htmlFor="model">AI Model</Label>
                          <Select
                            value={formData.model}
                            onValueChange={(v) => handleChange('model', v)}
                          >
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

                        <div className="space-y-4">
                          <div className="flex items-center justify-between">
                            <Label>Temperature: {formData.temperature}</Label>
                            <span className="text-xs text-muted-foreground">
                              Lower = more focused, Higher = more creative
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

                        <div className="space-y-2">
                          <Label htmlFor="max_tokens">Max Tokens</Label>
                          <Input
                            id="max_tokens"
                            type="number"
                            min={100}
                            max={8192}
                            value={formData.max_tokens}
                            onChange={(e) => {
                              const value = e.target.value;
                              const parsed = parseInt(value, 10);
                              if (!isNaN(parsed) || value === '') {
                                handleChange('max_tokens', value === '' ? 100 : parsed);
                              }
                            }}
                          />
                          <p className="text-xs text-muted-foreground">
                            Maximum number of tokens in the response (100-8192)
                          </p>
                        </div>
                      </CardContent>
                    </Card>
                  </TabsContent>

                  {/* Knowledge Base Tab */}
                  <TabsContent value="knowledge" className="space-y-4">
                    <Card>
                      <CardHeader>
                        <CardTitle>Knowledge Base Integration</CardTitle>
                        <CardDescription>
                          Connect this flow to your knowledge base for context-aware responses
                        </CardDescription>
                      </CardHeader>
                      <CardContent className="space-y-6">
                        {knowledgeBase ? (
                          <>
                            <div className="flex items-center justify-between p-4 border rounded-lg">
                              <div className="flex items-center gap-3">
                                <BookOpen className="h-5 w-5 text-muted-foreground" />
                                <div>
                                  <p className="font-medium">{knowledgeBase.name}</p>
                                  <p className="text-sm text-muted-foreground">
                                    {knowledgeBase.document_count} documents, {knowledgeBase.chunk_count} chunks
                                  </p>
                                </div>
                              </div>
                              <Switch
                                checked={formData.knowledge_base_id === knowledgeBase.id}
                                onCheckedChange={(checked) =>
                                  handleChange('knowledge_base_id', checked ? knowledgeBase.id : null)
                                }
                              />
                            </div>

                            {formData.knowledge_base_id && (
                              <div className="space-y-4 pt-4 border-t">
                                <div className="space-y-2">
                                  <Label>Top K Results: {formData.kb_top_k}</Label>
                                  <Slider
                                    value={[formData.kb_top_k || 5]}
                                    onValueChange={([v]) => handleChange('kb_top_k', v)}
                                    min={1}
                                    max={20}
                                    step={1}
                                  />
                                  <p className="text-xs text-muted-foreground">
                                    Number of relevant documents to include in context
                                  </p>
                                </div>

                                <div className="space-y-2">
                                  <Label>Similarity Threshold: {formData.kb_similarity_threshold}</Label>
                                  <Slider
                                    value={[formData.kb_similarity_threshold || 0.7]}
                                    onValueChange={([v]) => handleChange('kb_similarity_threshold', v)}
                                    min={0.1}
                                    max={1}
                                    step={0.05}
                                  />
                                  <p className="text-xs text-muted-foreground">
                                    Minimum similarity score for documents to be included
                                  </p>
                                </div>
                              </div>
                            )}
                          </>
                        ) : (
                          <div className="text-center py-8">
                            <BookOpen className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                            <p className="text-muted-foreground mb-4">
                              No knowledge base found for this bot
                            </p>
                            <Button variant="outline" asChild>
                              <a href={`/knowledge-base?botId=${botId}`}>Set up Knowledge Base</a>
                            </Button>
                          </div>
                        )}
                      </CardContent>
                    </Card>
                  </TabsContent>

                  {/* Advanced Tab */}
                  <TabsContent value="advanced" className="space-y-4">
                    <Card>
                      <CardHeader>
                        <CardTitle>Agentic Mode</CardTitle>
                        <CardDescription>
                          Enable autonomous tool usage for more complex tasks
                        </CardDescription>
                      </CardHeader>
                      <CardContent className="space-y-4">
                        <div className="flex items-center justify-between">
                          <div className="space-y-0.5">
                            <Label>Enable Agentic Mode</Label>
                            <p className="text-sm text-muted-foreground">
                              Allow the bot to use tools autonomously
                            </p>
                          </div>
                          <Switch
                            checked={formData.agentic_mode}
                            onCheckedChange={(checked) => handleChange('agentic_mode', checked)}
                          />
                        </div>

                        {formData.agentic_mode && (
                          <div className="space-y-2 pt-4 border-t">
                            <Label htmlFor="max_tool_calls">Max Tool Calls</Label>
                            <Input
                              id="max_tool_calls"
                              type="number"
                              min={1}
                              max={50}
                              value={formData.max_tool_calls}
                              onChange={(e) => {
                                const value = e.target.value;
                                const parsed = parseInt(value, 10);
                                if (!isNaN(parsed) || value === '') {
                                  handleChange('max_tool_calls', value === '' ? 1 : parsed);
                                }
                              }}
                            />
                            <p className="text-xs text-muted-foreground">
                              Maximum number of tool calls per conversation turn
                            </p>
                          </div>
                        )}
                      </CardContent>
                    </Card>
                  </TabsContent>
                </Tabs>
              </div>
            )}
          </div>

          {/* Chat Emulator - floating panel on the right - dabby.io style */}
          <div className="w-80 border-l bg-card flex flex-col shadow-lg">
            {/* Header */}
            <div className="flex items-center justify-between h-14 px-4 border-b">
              <div className="flex items-center gap-2">
                <MessageCircle className="h-4 w-4" />
                <span className="font-semibold text-sm">แชทจำลอง</span>
              </div>
            </div>

            {/* Messages area */}
            <div className="flex-1 overflow-y-auto p-4 space-y-3">
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
                    className={`max-w-xs rounded-lg px-3 py-2 text-sm ${
                      msg.role === 'user'
                        ? 'bg-amber-500 text-white'
                        : 'bg-muted text-muted-foreground'
                    }`}
                  >
                    {msg.content}
                  </div>
                </div>
              ))}
              <div ref={chatEndRef} />
            </div>

            {/* Input area */}
            <div className="border-t p-3 space-y-2">
              <input
                type="text"
                placeholder="พิมพ์ข้อความ..."
                className="w-full px-3 py-2 rounded-lg border bg-background text-sm"
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
                className="w-full bg-amber-500 hover:bg-amber-600"
              >
                <Send className="h-4 w-4 mr-2" />
                ส่ง
              </Button>
            </div>
          </div>
        </div>
      </div>

      {/* Unsaved changes indicator */}
      {hasChanges && (
        <div className="fixed bottom-4 left-1/2 -translate-x-1/2 bg-background border rounded-lg shadow-lg px-4 py-3 flex items-center gap-4">
          <span className="text-sm text-muted-foreground">You have unsaved changes</span>
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
              Discard
            </Button>
            <Button size="sm" onClick={handleSave} disabled={isSaving}>
              {isSaving ? <Loader2 className="h-4 w-4 animate-spin" /> : 'Save'}
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
