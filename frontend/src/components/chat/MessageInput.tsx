/**
 * T022: MessageInput component
 * Text input with send button
 * Shift+Enter for newline, disabled when not in handover
 */
import { useState, useRef, useCallback, type KeyboardEvent, type FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Loader2, Send, Headphones, Bot } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface MessageInputProps {
  onSend: (content: string) => void | Promise<void>;
  isLoading?: boolean;
  disabled?: boolean;
  isHandover?: boolean;
  placeholder?: string;
  className?: string;
}

export function MessageInput({
  onSend,
  isLoading = false,
  disabled = false,
  isHandover = true,
  placeholder = 'Type a message...',
  className,
}: MessageInputProps) {
  const [value, setValue] = useState('');
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  // Handle form submit
  const handleSubmit = useCallback(
    async (e: FormEvent) => {
      e.preventDefault();
      if (!value.trim() || isLoading || disabled) return;

      const content = value.trim();
      setValue('');
      await onSend(content);

      // Refocus textarea after send
      textareaRef.current?.focus();
    },
    [value, isLoading, disabled, onSend]
  );

  // Handle keyboard shortcuts
  const handleKeyDown = useCallback(
    (e: KeyboardEvent<HTMLTextAreaElement>) => {
      // Enter without shift = submit
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (value.trim() && !isLoading && !disabled) {
          handleSubmit(e as unknown as FormEvent);
        }
      }
    },
    [value, isLoading, disabled, handleSubmit]
  );

  // If not in handover mode, show bot indicator
  if (!isHandover) {
    return (
      <div className={cn('p-4 text-center text-sm text-muted-foreground', className)}>
        <Bot className="h-4 w-4 inline-block mr-1" />
        Bot is handling this conversation. Click "Take Over" to respond manually.
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className={cn('p-2 sm:p-3', className)}>
      <div className="flex gap-2 max-w-3xl mx-auto">
        <div className="flex-1 relative">
          <Textarea
            ref={textareaRef}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={placeholder}
            disabled={isLoading || disabled}
            className="min-h-[44px] max-h-32 resize-none pr-12 text-base sm:text-sm"
            rows={1}
            autoFocus
          />
          <div className="absolute right-3 top-1/2 -translate-y-1/2">
            <Headphones className="h-4 w-4 text-muted-foreground" />
          </div>
        </div>
        <Button
          type="submit"
          disabled={!value.trim() || isLoading || disabled}
          className="h-11 w-11 p-0 flex-shrink-0"
        >
          {isLoading ? (
            <Loader2 className="h-4 w-4 animate-spin" />
          ) : (
            <Send className="h-4 w-4" />
          )}
        </Button>
      </div>
      <p className="text-center text-xs text-muted-foreground mt-2 hidden sm:block">
        Handover mode - Press Enter to send, Shift+Enter for new line
      </p>
    </form>
  );
}

export default MessageInput;
