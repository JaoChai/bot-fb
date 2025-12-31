import { useRef } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Paperclip,
  Send,
  Loader2,
  X,
  FileIcon,
  Image as ImageIcon,
  Film,
} from 'lucide-react';

interface TelegramMessageInputProps {
  value: string;
  onChange: (value: string) => void;
  selectedMedia: File | null;
  onMediaSelect: (file: File | null) => void;
  onSubmit: (e: React.FormEvent) => void;
  isLoading: boolean;
}

export function TelegramMessageInput({
  value,
  onChange,
  selectedMedia,
  onMediaSelect,
  onSubmit,
  isLoading,
}: TelegramMessageInputProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);

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
      return <ImageIcon className="h-5 w-5 text-[#0088CC]" />;
    }
    if (file.type.startsWith('video/')) {
      return <Film className="h-5 w-5 text-[#0088CC]" />;
    }
    return <FileIcon className="h-5 w-5 text-[#0088CC]" />;
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

      <div className="flex gap-2 max-w-3xl mx-auto">
        {/* Hidden file input */}
        <input
          ref={fileInputRef}
          type="file"
          accept="image/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt"
          className="hidden"
          onChange={handleFileSelect}
        />

        {/* Media Upload Button */}
        <Button
          type="button"
          variant="outline"
          size="icon"
          className="h-11 w-11 flex-shrink-0"
          onClick={() => fileInputRef.current?.click()}
          disabled={isLoading}
        >
          <Paperclip className="h-5 w-5" />
        </Button>

        {/* Text Input */}
        <div className="flex-1 relative">
          <Input
            value={value}
            onChange={(e) => onChange(e.target.value)}
            placeholder={selectedMedia ? 'เพิ่มคำอธิบาย...' : 'พิมพ์ข้อความ...'}
            disabled={isLoading}
            className="pr-4 min-h-[44px] text-base sm:text-sm"
            autoFocus
          />
        </div>

        {/* Send Button */}
        <Button
          type="submit"
          disabled={(!value.trim() && !selectedMedia) || isLoading}
          className="h-11 w-11 p-0 bg-[#0088CC] hover:bg-[#0088CC]/90"
        >
          {isLoading ? (
            <Loader2 className="h-5 w-5 animate-spin" />
          ) : (
            <Send className="h-5 w-5" />
          )}
        </Button>
      </div>

      <p className="text-center text-xs text-muted-foreground mt-2 hidden sm:block">
        ข้อความจะส่งไปยัง Telegram โดยตรง
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
