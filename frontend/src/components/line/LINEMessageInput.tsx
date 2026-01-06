import { useRef, useEffect, useState, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import {
  Paperclip,
  Send,
  Loader2,
  X,
  FileIcon,
  Image as ImageIcon,
  Film,
} from 'lucide-react';
import { QuickReplyButton } from '@/components/chat/QuickReplyButton';
import { QuickReplyAutocomplete } from '@/components/chat/QuickReplyAutocomplete';
import type { QuickReply } from '@/types/quick-reply';

interface LINEMessageInputProps {
  value: string;
  onChange: (value: string) => void;
  selectedMedia: File | null;
  onMediaSelect: (file: File | null) => void;
  onSubmit: (e: React.FormEvent) => void;
  isLoading: boolean;
  // Quick Reply props
  onQuickReplySelect?: (quickReply: QuickReply) => void;
  showQuickReply?: boolean;
}

export function LINEMessageInput({
  value,
  onChange,
  selectedMedia,
  onMediaSelect,
  onSubmit,
  isLoading,
  onQuickReplySelect,
  showQuickReply = true,
}: LINEMessageInputProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const [showAutocomplete, setShowAutocomplete] = useState(false);

  // Auto-resize textarea
  useEffect(() => {
    const textarea = textareaRef.current;
    if (textarea) {
      textarea.style.height = 'auto';
      textarea.style.height = `${Math.min(textarea.scrollHeight, 120)}px`;
    }
  }, [value]);

  // Handle text change with Quick Reply detection
  const handleTextChange = useCallback((newValue: string) => {
    onChange(newValue);
    // Show autocomplete when input starts with / (e.g., "/hello", "/")
    setShowAutocomplete(newValue.match(/^\/[a-z0-9_-]*$/i) !== null);
  }, [onChange]);

  // Handle Quick Reply selection
  const handleQuickReplySelect = useCallback((quickReply: QuickReply) => {
    setShowAutocomplete(false);
    onQuickReplySelect?.(quickReply);
  }, [onQuickReplySelect]);

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (value.trim() || selectedMedia) {
        onSubmit(e as unknown as React.FormEvent);
      }
    }
  };

  const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (file) {
      onMediaSelect(file);
    }
    // Reset input so same file can be selected again
    e.target.value = '';
  };

  const getFileIcon = (file: File) => {
    if (file.type.startsWith('image/')) {
      return <ImageIcon className="h-5 w-5 text-[#06C755]" />;
    }
    if (file.type.startsWith('video/')) {
      return <Film className="h-5 w-5 text-[#06C755]" />;
    }
    return <FileIcon className="h-5 w-5 text-[#06C755]" />;
  };

  const getFilePreview = (file: File) => {
    if (file.type.startsWith('image/')) {
      return (
        <img
          src={URL.createObjectURL(file)}
          alt="Preview"
          className="h-14 w-14 object-cover rounded"
        />
      );
    }
    return (
      <div className="h-14 w-14 rounded bg-muted flex items-center justify-center">
        {getFileIcon(file)}
      </div>
    );
  };

  return (
    <form onSubmit={onSubmit} className="p-2 sm:p-3">
      {/* Media Preview */}
      {selectedMedia && (
        <div className="mb-2 max-w-3xl mx-auto">
          <div className="flex items-center gap-3 p-2 bg-muted rounded-lg">
            {getFilePreview(selectedMedia)}
            <div className="flex-1 min-w-0">
              <p className="text-sm font-medium truncate">{selectedMedia.name}</p>
              <p className="text-xs text-muted-foreground">
                {formatFileSize(selectedMedia.size)}
              </p>
            </div>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className="h-8 w-8 flex-shrink-0"
              onClick={() => onMediaSelect(null)}
            >
              <X className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}

      {/* LINE OA Style Input Container */}
      <div className="flex items-end gap-2 max-w-3xl mx-auto relative">
        {/* Hidden file input - LINE supports image, video, audio */}
        <input
          ref={fileInputRef}
          type="file"
          accept="image/*,video/*,audio/*"
          className="hidden"
          onChange={handleFileSelect}
        />

        {/* Media Upload Button */}
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-10 w-10 flex-shrink-0 text-muted-foreground hover:text-foreground"
          onClick={() => fileInputRef.current?.click()}
          disabled={isLoading}
        >
          <Paperclip className="h-5 w-5" />
        </Button>

        {/* Quick Reply Button - LINE OA style */}
        {showQuickReply && onQuickReplySelect && (
          <QuickReplyButton
            onSelect={handleQuickReplySelect}
            disabled={isLoading}
            variant="ghost"
            className="h-10 w-10 text-muted-foreground hover:text-foreground"
          />
        )}

        {/* Text Input Container - LINE OA Style */}
        <div className="flex-1 flex items-end gap-2 px-4 py-2 bg-muted/50 rounded-2xl border border-input focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1 relative">
          {/* Quick Reply Autocomplete - shows above input */}
          {showQuickReply && showAutocomplete && onQuickReplySelect && (
            <QuickReplyAutocomplete
              inputValue={value}
              onSelect={handleQuickReplySelect}
              onClose={() => setShowAutocomplete(false)}
            />
          )}

          <textarea
            ref={textareaRef}
            value={value}
            onChange={(e) => handleTextChange(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={selectedMedia ? 'เพิ่มคำอธิบาย...' : 'พิมพ์ข้อความ หรือ / เพื่อใช้ Quick Reply...'}
            disabled={isLoading}
            rows={1}
            className="flex-1 min-h-[24px] max-h-[120px] py-0 text-base sm:text-sm resize-none bg-transparent focus:outline-none disabled:cursor-not-allowed disabled:opacity-50 placeholder:text-muted-foreground"
            autoFocus
          />

          {/* Send Button inside input - LINE Green */}
          <Button
            type="submit"
            size="icon"
            disabled={(!value.trim() && !selectedMedia) || isLoading}
            className="h-8 w-8 flex-shrink-0 rounded-full bg-[#06C755] hover:bg-[#06C755]/90 disabled:bg-muted disabled:text-muted-foreground"
          >
            {isLoading ? (
              <Loader2 className="h-4 w-4 animate-spin" />
            ) : (
              <Send className="h-4 w-4" />
            )}
          </Button>
        </div>
      </div>

      <p className="text-center text-xs text-muted-foreground mt-2 hidden sm:block">
        ข้อความจะส่งไปยัง LINE โดยตรง • Shift+Enter ขึ้นบรรทัดใหม่
      </p>
    </form>
  );
}

// Helper function to format file size
function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
