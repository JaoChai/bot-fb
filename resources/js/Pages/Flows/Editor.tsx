import { useState, useRef, useEffect, useCallback } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Slider } from '@/Components/ui/slider';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { Separator } from '@/Components/ui/separator';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import {
  ArrowLeft,
  Save,
  Loader2,
  Send,
  Bot,
  User,
  Square,
  Trash2,
  RefreshCw,
  Settings,
  Sparkles,
  Database,
  MessageSquare,
  ChevronDown,
  ChevronUp,
} from 'lucide-react';
import { cn } from '@/Lib/utils';
import { useStreamingChat } from '@/Hooks/useStreamingChat';
import type { SharedProps } from '@/types';

// Flow type based on provided interface
interface Flow {
  id: number;
  name: string;
  system_prompt: string;
  model: string;
  temperature: number;
  max_tokens: number;
  presence_penalty?: number;
  frequency_penalty?: number;
  top_p?: number;
  is_active?: boolean;
  bot: {
    id: number;
    name: string;
  };
  knowledge_base?: {
    id: number;
    name: string;
  } | null;
}

interface ModelOption {
  value: string;
  label: string;
}

interface KnowledgeBaseOption {
  id: number;
  name: string;
}

interface EditorPageProps extends SharedProps {
  flow: Flow;
  models: ModelOption[];
  knowledgeBases: KnowledgeBaseOption[];
}

// Chat message interface for the emulator
interface ChatMessage {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  timestamp: Date;
}

export default function Editor() {
  const { flow, models, knowledgeBases, flash } = usePage<EditorPageProps>().props;

  // Form state for flow settings
  const { data, setData, put, processing, errors, isDirty, reset } = useForm({
    name: flow.name || '',
    system_prompt: flow.system_prompt || '',
    model: flow.model || '',
    temperature: flow.temperature ?? 0.7,
    max_tokens: flow.max_tokens ?? 1024,
    presence_penalty: flow.presence_penalty ?? 0,
    frequency_penalty: flow.frequency_penalty ?? 0,
    top_p: flow.top_p ?? 1,
    knowledge_base_id: flow.knowledge_base?.id || null,
  });

  // Chat emulator state
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [messageInput, setMessageInput] = useState('');
  const [settingsExpanded, setSettingsExpanded] = useState(true);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  // Streaming chat hook
  const {
    sendMessage: streamSendMessage,
    cancel: cancelStream,
    reset: resetStream,
    isStreaming,
    fullResponse,
    state: streamState,
    error: streamError,
  } = useStreamingChat({
    endpoint: `/api/flows/${flow.id}/test`,
    onComplete: (response) => {
      // Add the completed assistant message
      setMessages((prev) => {
        // Remove the streaming placeholder and add the final message
        const withoutStreaming = prev.filter((m) => m.id !== 'streaming');
        return [
          ...withoutStreaming,
          {
            id: `assistant-${Date.now()}`,
            role: 'assistant',
            content: response,
            timestamp: new Date(),
          },
        ];
      });
    },
    onError: (error) => {
      // Remove streaming placeholder on error
      setMessages((prev) => prev.filter((m) => m.id !== 'streaming'));
      console.error('Streaming error:', error);
    },
  });

  // Scroll to bottom when messages change
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages, fullResponse]);

  // Handle form submission
  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    put(`/flows/${flow.id}`, {
      preserveScroll: true,
    });
  };

  // Handle chat message send
  const handleSendMessage = useCallback(async () => {
    if (!messageInput.trim() || isStreaming) return;

    const userMessage: ChatMessage = {
      id: `user-${Date.now()}`,
      role: 'user',
      content: messageInput.trim(),
      timestamp: new Date(),
    };

    setMessages((prev) => [...prev, userMessage]);
    setMessageInput('');

    // Add streaming placeholder
    setMessages((prev) => [
      ...prev,
      {
        id: 'streaming',
        role: 'assistant',
        content: '',
        timestamp: new Date(),
      },
    ]);

    // Send message with current form data as context
    await streamSendMessage(userMessage.content, {
      system_prompt: data.system_prompt,
      model: data.model,
      temperature: data.temperature,
      max_tokens: data.max_tokens,
      knowledge_base_id: data.knowledge_base_id,
    });
  }, [messageInput, isStreaming, streamSendMessage, data]);

  // Handle keyboard shortcuts
  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      handleSendMessage();
    }
  };

  // Clear chat history
  const handleClearChat = () => {
    setMessages([]);
    resetStream();
  };

  return (
    <AuthenticatedLayout header={`แก้ไข Flow: ${flow.name}`}>
      <Head title={`Flow Editor - ${flow.name}`} />

      <div className="h-[calc(100vh-8rem)] flex flex-col gap-4">
        {/* Header */}
        <div className="flex items-center justify-between gap-4 shrink-0">
          <div className="flex items-center gap-4">
            <Button variant="ghost" size="sm" asChild>
              <Link href={`/bots/${flow.bot.id}/settings`}>
                <ArrowLeft className="h-4 w-4 mr-2" />
                กลับ
              </Link>
            </Button>
            <div>
              <h1 className="text-xl font-bold">{flow.name}</h1>
              <p className="text-sm text-muted-foreground">
                Bot: {flow.bot.name}
                {flow.knowledge_base && ` | KB: ${flow.knowledge_base.name}`}
              </p>
            </div>
          </div>

          <Button onClick={handleSubmit} disabled={processing || !isDirty}>
            {processing ? (
              <Loader2 className="h-4 w-4 animate-spin mr-2" />
            ) : (
              <Save className="h-4 w-4 mr-2" />
            )}
            บันทึก
          </Button>
        </div>

        {/* Flash Messages */}
        {flash?.success && (
          <div className="rounded-lg border bg-green-50 dark:bg-green-950 p-4 text-green-700 dark:text-green-300 shrink-0">
            {flash.success}
          </div>
        )}
        {flash?.error && (
          <div className="rounded-lg border bg-red-50 dark:bg-red-950 p-4 text-red-700 dark:text-red-300 shrink-0">
            {flash.error}
          </div>
        )}

        {/* Main Content: Side-by-side on desktop, stacked on mobile */}
        <div className="flex-1 grid lg:grid-cols-2 gap-4 min-h-0">
          {/* Left Panel: Flow Settings */}
          <Card className="flex flex-col min-h-0 lg:order-1 order-2">
            <CardHeader className="shrink-0 pb-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Settings className="h-5 w-5 text-muted-foreground" />
                  <CardTitle>Flow Settings</CardTitle>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setSettingsExpanded(!settingsExpanded)}
                  className="lg:hidden"
                >
                  {settingsExpanded ? (
                    <ChevronUp className="h-4 w-4" />
                  ) : (
                    <ChevronDown className="h-4 w-4" />
                  )}
                </Button>
              </div>
              <CardDescription>กำหนดค่าการตอบสนองของ AI</CardDescription>
            </CardHeader>

            <ScrollArea className={cn('flex-1', !settingsExpanded && 'lg:block hidden')}>
              <form onSubmit={handleSubmit}>
                <CardContent className="space-y-6 pb-6">
                  {/* Basic Info Section */}
                  <div className="space-y-4">
                    <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                      <Sparkles className="h-4 w-4" />
                      ข้อมูลพื้นฐาน
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="name">ชื่อ Flow</Label>
                      <Input
                        id="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="ชื่อ Flow..."
                      />
                      {errors.name && (
                        <p className="text-sm text-destructive">{errors.name}</p>
                      )}
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="system_prompt">System Prompt</Label>
                      <Textarea
                        id="system_prompt"
                        value={data.system_prompt}
                        onChange={(e) => setData('system_prompt', e.target.value)}
                        placeholder="กำหนดบุคลิกและพฤติกรรมของ AI..."
                        className="min-h-[150px] resize-y"
                      />
                      {errors.system_prompt && (
                        <p className="text-sm text-destructive">{errors.system_prompt}</p>
                      )}
                    </div>
                  </div>

                  <Separator />

                  {/* Model Settings Section */}
                  <div className="space-y-4">
                    <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                      <Bot className="h-4 w-4" />
                      ตั้งค่าโมเดล
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="model">โมเดล AI</Label>
                      <Select
                        value={data.model}
                        onValueChange={(value) => setData('model', value)}
                      >
                        <SelectTrigger className="w-full">
                          <SelectValue placeholder="เลือกโมเดล" />
                        </SelectTrigger>
                        <SelectContent>
                          {models.map((model) => (
                            <SelectItem key={model.value} value={model.value}>
                              {model.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      {errors.model && (
                        <p className="text-sm text-destructive">{errors.model}</p>
                      )}
                    </div>

                    {/* Temperature */}
                    <div className="space-y-3">
                      <div className="flex items-center justify-between">
                        <Label>Temperature</Label>
                        <span className="text-sm text-muted-foreground">
                          {data.temperature.toFixed(2)}
                        </span>
                      </div>
                      <Slider
                        value={[data.temperature]}
                        onValueChange={([value]) => setData('temperature', value)}
                        min={0}
                        max={2}
                        step={0.01}
                      />
                      <p className="text-xs text-muted-foreground">
                        ค่าสูง = สร้างสรรค์มากขึ้น, ค่าต่ำ = แม่นยำมากขึ้น
                      </p>
                    </div>

                    {/* Max Tokens */}
                    <div className="space-y-3">
                      <div className="flex items-center justify-between">
                        <Label>Max Tokens</Label>
                        <span className="text-sm text-muted-foreground">
                          {data.max_tokens}
                        </span>
                      </div>
                      <Slider
                        value={[data.max_tokens]}
                        onValueChange={([value]) => setData('max_tokens', value)}
                        min={100}
                        max={4096}
                        step={50}
                      />
                      <p className="text-xs text-muted-foreground">
                        จำนวน tokens สูงสุดในการตอบกลับ
                      </p>
                    </div>

                    {/* Top P */}
                    <div className="space-y-3">
                      <div className="flex items-center justify-between">
                        <Label>Top P</Label>
                        <span className="text-sm text-muted-foreground">
                          {(data.top_p ?? 1).toFixed(2)}
                        </span>
                      </div>
                      <Slider
                        value={[data.top_p ?? 1]}
                        onValueChange={([value]) => setData('top_p', value)}
                        min={0}
                        max={1}
                        step={0.01}
                      />
                    </div>

                    {/* Presence Penalty */}
                    <div className="space-y-3">
                      <div className="flex items-center justify-between">
                        <Label>Presence Penalty</Label>
                        <span className="text-sm text-muted-foreground">
                          {(data.presence_penalty ?? 0).toFixed(2)}
                        </span>
                      </div>
                      <Slider
                        value={[data.presence_penalty ?? 0]}
                        onValueChange={([value]) => setData('presence_penalty', value)}
                        min={-2}
                        max={2}
                        step={0.1}
                      />
                    </div>

                    {/* Frequency Penalty */}
                    <div className="space-y-3">
                      <div className="flex items-center justify-between">
                        <Label>Frequency Penalty</Label>
                        <span className="text-sm text-muted-foreground">
                          {(data.frequency_penalty ?? 0).toFixed(2)}
                        </span>
                      </div>
                      <Slider
                        value={[data.frequency_penalty ?? 0]}
                        onValueChange={([value]) => setData('frequency_penalty', value)}
                        min={-2}
                        max={2}
                        step={0.1}
                      />
                    </div>
                  </div>

                  <Separator />

                  {/* Knowledge Base Section */}
                  {knowledgeBases.length > 0 && (
                    <div className="space-y-4">
                      <div className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                        <Database className="h-4 w-4" />
                        ฐานความรู้
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="knowledge_base">เลือกฐานความรู้</Label>
                        <Select
                          value={data.knowledge_base_id?.toString() || 'none'}
                          onValueChange={(value) =>
                            setData('knowledge_base_id', value === 'none' ? null : parseInt(value))
                          }
                        >
                          <SelectTrigger className="w-full">
                            <SelectValue placeholder="ไม่ใช้ฐานความรู้" />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="none">ไม่ใช้ฐานความรู้</SelectItem>
                            {knowledgeBases.map((kb) => (
                              <SelectItem key={kb.id} value={kb.id.toString()}>
                                {kb.name}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                    </div>
                  )}
                </CardContent>
              </form>
            </ScrollArea>
          </Card>

          {/* Right Panel: Chat Emulator */}
          <Card className="flex flex-col min-h-0 lg:order-2 order-1">
            <CardHeader className="shrink-0 pb-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <MessageSquare className="h-5 w-5 text-muted-foreground" />
                  <CardTitle>Chat Emulator</CardTitle>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={handleClearChat}
                  disabled={messages.length === 0 && !isStreaming}
                >
                  <RefreshCw className="h-4 w-4 mr-2" />
                  ล้าง
                </Button>
              </div>
              <CardDescription>ทดสอบการตอบกลับของ AI แบบ real-time</CardDescription>
            </CardHeader>

            {/* Messages Area */}
            <ScrollArea className="flex-1 px-6">
              <div className="space-y-4 pb-4">
                {messages.length === 0 ? (
                  <div className="flex flex-col items-center justify-center py-12 text-center">
                    <Bot className="h-12 w-12 text-muted-foreground/50 mb-4" />
                    <p className="text-muted-foreground">ยังไม่มีข้อความ</p>
                    <p className="text-sm text-muted-foreground/70">
                      เริ่มพิมพ์ข้อความเพื่อทดสอบ Flow
                    </p>
                  </div>
                ) : (
                  messages.map((message) => (
                    <div
                      key={message.id}
                      className={cn(
                        'flex gap-3',
                        message.role === 'user' ? 'justify-end' : 'justify-start'
                      )}
                    >
                      {message.role === 'assistant' && (
                        <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                          <Bot className="h-4 w-4 text-primary" />
                        </div>
                      )}

                      <div
                        className={cn(
                          'max-w-[80%] rounded-lg px-4 py-2',
                          message.role === 'user'
                            ? 'bg-primary text-primary-foreground'
                            : 'bg-muted'
                        )}
                      >
                        <p className="text-sm whitespace-pre-wrap">
                          {message.id === 'streaming' ? fullResponse || '...' : message.content}
                        </p>
                        {message.id === 'streaming' && isStreaming && (
                          <span className="inline-block w-2 h-4 bg-current animate-pulse ml-1" />
                        )}
                      </div>

                      {message.role === 'user' && (
                        <div className="flex-shrink-0 w-8 h-8 rounded-full bg-secondary flex items-center justify-center">
                          <User className="h-4 w-4" />
                        </div>
                      )}
                    </div>
                  ))
                )}
                <div ref={messagesEndRef} />
              </div>
            </ScrollArea>

            {/* Error Message */}
            {streamError && (
              <div className="px-6 pb-4">
                <div className="rounded-lg border border-destructive bg-destructive/10 p-3 text-sm text-destructive">
                  {streamError.message}
                </div>
              </div>
            )}

            {/* Input Area */}
            <div className="p-4 border-t shrink-0">
              <div className="flex gap-2">
                <Textarea
                  value={messageInput}
                  onChange={(e) => setMessageInput(e.target.value)}
                  onKeyDown={handleKeyDown}
                  placeholder="พิมพ์ข้อความเพื่อทดสอบ..."
                  className="min-h-[44px] max-h-[120px] resize-none"
                  disabled={isStreaming}
                />

                {isStreaming ? (
                  <Button
                    variant="destructive"
                    size="icon"
                    onClick={cancelStream}
                    className="shrink-0 h-11 w-11"
                  >
                    <Square className="h-4 w-4" />
                  </Button>
                ) : (
                  <Button
                    onClick={handleSendMessage}
                    disabled={!messageInput.trim()}
                    className="shrink-0 h-11 w-11"
                    size="icon"
                  >
                    <Send className="h-4 w-4" />
                  </Button>
                )}
              </div>
              <p className="text-xs text-muted-foreground mt-2">
                กด Enter เพื่อส่ง, Shift+Enter เพื่อขึ้นบรรทัดใหม่
              </p>
            </div>
          </Card>
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
