import { memo, useCallback, useRef, useEffect, useState } from 'react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { ProcessDisplay } from '@/components/ProcessDisplay';
import {
  MessageCircle,
  Send,
  Trash2,
  Paperclip,
  Image as ImageIcon,
  Loader2,
  Square,
} from 'lucide-react';
import type { StreamingMessage } from '@/hooks/useStreamingChat';

interface ChatEmulatorProps {
  messages: StreamingMessage[];
  isStreaming: boolean;
  onSendMessage: (message: string) => Promise<void>;
  onCancelStream: () => void;
  onClearMessages: () => void;
  disabled?: boolean;
  disabledReason?: string;
  className?: string;
}

// Memoized message item
const ChatMessageItem = memo(function ChatMessageItem({
  message,
}: {
  message: StreamingMessage;
}) {
  return (
    <div className={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
      <div className={`max-w-[85%] ${message.role === 'assistant' ? 'w-full' : ''}`}>
        {/* Show process display for assistant messages */}
        {message.role === 'assistant' && (message.processLogs?.length || message.isStreaming) && (
          <ProcessDisplay
            logs={message.processLogs || []}
            summary={message.summary}
            isStreaming={message.isStreaming}
          />
        )}
        <div
          className={`rounded-2xl px-4 py-2.5 text-sm ${
            message.role === 'user'
              ? 'bg-foreground text-background rounded-br-md'
              : 'bg-muted rounded-bl-md'
          }`}
        >
          {message.content}
          {/* Show streaming cursor */}
          {message.role === 'assistant' && message.isStreaming && !message.content && (
            <span className="flex items-center gap-1 text-muted-foreground">
              <Loader2 className="h-3 w-3 animate-spin" />
              <span>กำลังตอบ...</span>
            </span>
          )}
          {message.role === 'assistant' && message.isStreaming && message.content && (
            <span className="animate-pulse text-foreground">|</span>
          )}
        </div>
      </div>
    </div>
  );
});

export const ChatEmulator = memo(function ChatEmulator({
  messages,
  isStreaming,
  onSendMessage,
  onCancelStream,
  onClearMessages,
  disabled = false,
  disabledReason,
  className,
}: ChatEmulatorProps) {
  const [input, setInput] = useState('');
  const chatEndRef = useRef<HTMLDivElement>(null);

  // Scroll to bottom when messages change
  useEffect(() => {
    chatEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages]);

  // Handle send message
  const handleSend = useCallback(async () => {
    if (!input.trim() || disabled) return;
    const message = input.trim();
    setInput('');
    await onSendMessage(message);
  }, [input, disabled, onSendMessage]);

  // Handle key press
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Enter' && !e.shiftKey && !isStreaming && !disabled) {
        e.preventDefault();
        handleSend();
      }
    },
    [handleSend, isStreaming, disabled]
  );

  return (
    <div className={cn("w-96 border-l bg-card flex flex-col", className)}>
      {/* Header */}
      <div className="flex items-center justify-between px-4 py-3 text-background bg-foreground border-b">
        <div className="flex items-center gap-2">
          <MessageCircle className="h-5 w-5" />
          <span className="font-semibold">แชทจำลอง</span>
        </div>
        <div className="flex items-center gap-2">
          <Button
            size="sm"
            variant="ghost"
            className="h-8 w-8 p-0 text-white hover:bg-white/20"
            onClick={onClearMessages}
            title="ล้างแชท"
          >
            <Trash2 className="h-4 w-4" />
          </Button>
        </div>
      </div>

      {/* Messages Area */}
      <div className="flex-1 overflow-y-auto p-4 space-y-3">
        {messages.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-full text-center">
            <MessageCircle className="h-12 w-12 text-muted-foreground/30 mb-3" />
            <p className="text-sm text-muted-foreground">
              ทดสอบการตอบกลับของ AI
            </p>
            <p className="text-xs text-muted-foreground/70 mt-1">
              {disabled ? disabledReason : 'พิมพ์ข้อความด้านล่างเพื่อเริ่มต้น'}
            </p>
          </div>
        ) : (
          messages.map((msg) => (
            <ChatMessageItem key={msg.id} message={msg} />
          ))
        )}
        <div ref={chatEndRef} />
      </div>

      {/* Input Area */}
      <div className="border-t p-4 space-y-3 bg-background/50">
        <div className="flex gap-2">
          <input
            type="text"
            placeholder={isStreaming ? 'กำลังประมวลผล...' : disabled ? disabledReason : 'พิมพ์ข้อความ...'}
            className="flex-1 px-4 py-2.5 rounded-full border bg-background text-sm focus:outline-none focus:ring-2 focus:ring-ring/50"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={handleKeyDown}
            disabled={isStreaming || disabled}
          />
          {isStreaming ? (
            <Button
              size="icon"
              onClick={onCancelStream}
              variant="destructive"
              className="rounded-full h-10 w-10"
              title="หยุดการตอบ"
            >
              <Square className="h-4 w-4" />
            </Button>
          ) : (
            <Button
              size="icon"
              onClick={handleSend}
              disabled={!input.trim() || disabled}
              variant="default"
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
  );
});
