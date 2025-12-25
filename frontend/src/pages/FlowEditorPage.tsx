import { useState, useEffect } from 'react';
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
import { useFlow, useCreateFlow, useUpdateFlow, useFlowTemplates } from '@/hooks/useFlows';
import { useKnowledgeBase } from '@/hooks/useKnowledgeBase';
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
} from 'lucide-react';
import type { CreateFlowData, FlowTemplate } from '@/types/api';

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
  const isEditing = !!flowId && flowId !== 'new';
  const flowIdNum = isEditing ? parseInt(flowId, 10) : null;

  // Fetch existing flow if editing
  const { data: existingFlow, isLoading: isLoadingFlow } = useFlow(botId, flowIdNum);

  // Fetch knowledge base
  const { data: knowledgeBase } = useKnowledgeBase(botId);

  // Fetch templates
  const { data: templates, isLoading: isTemplatesLoading } = useFlowTemplates();

  // Mutations
  const createMutation = useCreateFlow(botId);
  const updateMutation = useUpdateFlow(botId, flowIdNum);

  // Form state
  const [formData, setFormData] = useState<CreateFlowData>({
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
  });

  const [templateDialogOpen, setTemplateDialogOpen] = useState(false);
  const [hasChanges, setHasChanges] = useState(false);

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
      if (isEditing) {
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
        navigate(`/flows?botId=${botId}`);
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

  // Loading state
  if (isEditing && isLoadingFlow) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  // No bot selected
  if (!botId) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-destructive">No bot selected. Please go back and select a bot.</p>
      </div>
    );
  }

  const isSaving = createMutation.isPending || updateMutation.isPending;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate(`/flows?botId=${botId}`)}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <h1 className="text-2xl font-bold tracking-tight">
              {isEditing ? 'Edit Flow' : 'Create New Flow'}
            </h1>
            <p className="text-muted-foreground">
              {isEditing ? 'Modify your conversation flow settings' : 'Define how your bot responds'}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {/* Template selector */}
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

          {/* Save button */}
          <Button onClick={handleSave} disabled={isSaving || !hasChanges}>
            {isSaving ? (
              <>
                <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                Saving...
              </>
            ) : (
              <>
                <Save className="h-4 w-4 mr-2" />
                {isEditing ? 'Save Changes' : 'Create Flow'}
              </>
            )}
          </Button>
        </div>
      </div>

      {/* Main content */}
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
                    onValueChange={(v) => handleChange('language', v as 'th' | 'en' | 'zh' | 'ja' | 'ko')}
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
                  onChange={(e) => handleChange('max_tokens', parseInt(e.target.value, 10))}
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
                    onChange={(e) => handleChange('max_tool_calls', parseInt(e.target.value, 10))}
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
