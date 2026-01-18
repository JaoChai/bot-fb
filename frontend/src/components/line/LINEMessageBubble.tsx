import { memo, useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
  Dialog,
  DialogContent,
} from '@/components/ui/dialog';
import { LazyImage } from '@/components/ui/lazy-image';
import {
  Headphones,
  MapPin,
  FileIcon,
  Play,
  Download,
  ExternalLink,
  Smile,
} from 'lucide-react';
import { format } from 'date-fns';
import { th } from 'date-fns/locale';
import { cn } from '@/lib/utils';
import type { Message } from '@/types/api';

interface LINEMessageBubbleProps {
  message: Message;
  previousMessage?: Message;
}

// Helper function to format file size
function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

// Memoized message bubble
export const LINEMessageBubble = memo(function LINEMessageBubble({
  message,
  previousMessage,
}: LINEMessageBubbleProps) {
  const [lightboxOpen, setLightboxOpen] = useState(false);
  const isUser = message.sender === 'user';

  // Show timestamp if more than 5 minutes since last message
  const showTimestamp =
    !previousMessage ||
    new Date(message.created_at).getTime() - new Date(previousMessage.created_at).getTime() >
      5 * 60 * 1000;

  // Show sender change indicator
  const senderChanged = previousMessage && previousMessage.sender !== message.sender;

  const renderContent = () => {
    const type = message.type || 'text';
    const metadata = message.media_metadata as Record<string, unknown> | null;

    switch (type) {
      case 'text':
        return (
          <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">{message.content}</p>
        );

      case 'image':
        return (
          <div className="max-w-[280px]">
            {message.media_url ? (
              <>
                <LazyImage
                  src={message.media_url}
                  alt="รูปภาพ"
                  className="rounded-lg cursor-pointer hover:opacity-90 transition-opacity w-full"
                  placeholderClassName="min-h-[150px] w-full"
                  onClick={() => setLightboxOpen(true)}
                />
                {message.content && !message.content.includes('[รูปภาพ]') && (
                  <p className="mt-2 text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">{message.content}</p>
                )}
              </>
            ) : (
              <div className="bg-muted/50 rounded-lg p-4 text-center text-muted-foreground">
                <FileIcon className="h-8 w-8 mx-auto mb-2" />
                <p className="text-sm">ไม่สามารถโหลดรูปภาพได้</p>
              </div>
            )}
          </div>
        );

      case 'video':
        return (
          <div className="max-w-[280px]">
            {message.media_url ? (
              <>
                <video
                  src={message.media_url}
                  controls
                  className="rounded-lg w-full"
                  preload="metadata"
                >
                  เบราว์เซอร์ของคุณไม่รองรับวิดีโอ
                </video>
                {message.content && !message.content.includes('[วิดีโอ]') && (
                  <p className="mt-2 text-sm whitespace-pre-wrap [overflow-wrap:anywhere]">{message.content}</p>
                )}
              </>
            ) : (
              <div className="bg-muted/50 rounded-lg p-4 text-center text-muted-foreground">
                <Play className="h-8 w-8 mx-auto mb-2" />
                <p className="text-sm">ไม่สามารถโหลดวิดีโอได้</p>
              </div>
            )}
          </div>
        );

      case 'audio':
        return (
          <div className="flex items-center gap-2 min-w-[200px]">
            {message.media_url ? (
              <audio
                src={message.media_url}
                controls
                className="w-full max-w-[250px]"
              />
            ) : (
              <p className="text-muted-foreground text-sm">ไฟล์เสียง</p>
            )}
          </div>
        );

      case 'file':
        const fileName = (metadata?.file_name as string) || 'ไฟล์';
        return (
          <a
            href={message.media_url || '#'}
            target="_blank"
            rel="noopener noreferrer"
            className={cn(
              'flex items-center gap-3 p-3 rounded-lg transition-colors',
              isUser ? 'bg-muted/50 hover:bg-muted' : 'bg-background/10 hover:bg-background/20'
            )}
          >
            <div className="h-10 w-10 rounded-lg bg-[#06C755]/10 flex items-center justify-center flex-shrink-0">
              <FileIcon className="h-5 w-5 text-[#06C755]" />
            </div>
            <div className="flex-1 min-w-0">
              <p className="font-medium text-sm truncate">{fileName}</p>
              {metadata?.file_size != null && (
                <p className="text-xs text-muted-foreground">
                  {formatFileSize(metadata.file_size as number)}
                </p>
              )}
            </div>
            <Download className="h-4 w-4 flex-shrink-0 opacity-60" />
          </a>
        );

      case 'sticker':
        return (
          <div className="max-w-[120px]">
            {message.media_url ? (
              <img
                src={message.media_url}
                alt="สติกเกอร์"
                className="w-full h-auto"
                loading="lazy"
              />
            ) : (
              <div className="flex items-center gap-2 p-2">
                <Smile className="h-8 w-8 text-[#06C755]" />
                <span className="text-muted-foreground text-sm">สติกเกอร์</span>
              </div>
            )}
          </div>
        );

      case 'location':
        // Parse location from content: "[ตำแหน่ง] address (lat, lng)"
        const locationMatch = message.content?.match(/\[ตำแหน่ง\]\s*([^(]*)\s*\(([^,]+),\s*([^)]+)\)/);
        const address = locationMatch?.[1]?.trim() || '';
        const lat = parseFloat(locationMatch?.[2] || '0');
        const lng = parseFloat(locationMatch?.[3] || '0');
        const mapsUrl = lat && lng
          ? `https://www.google.com/maps?q=${lat},${lng}`
          : '#';

        return (
          <a
            href={mapsUrl}
            target="_blank"
            rel="noopener noreferrer"
            className={cn(
              'flex items-center gap-3 p-3 rounded-lg transition-colors min-w-[200px]',
              isUser ? 'bg-muted/50 hover:bg-muted' : 'bg-background/10 hover:bg-background/20'
            )}
          >
            <div className="h-10 w-10 rounded-lg bg-red-500/10 flex items-center justify-center flex-shrink-0">
              <MapPin className="h-5 w-5 text-red-500" />
            </div>
            <div className="flex-1 min-w-0">
              <p className="font-medium text-sm truncate">
                {address || 'ตำแหน่งที่แชร์'}
              </p>
              {lat && lng && (
                <p className="text-xs text-muted-foreground truncate">
                  {lat.toFixed(6)}, {lng.toFixed(6)}
                </p>
              )}
            </div>
            <ExternalLink className="h-4 w-4 flex-shrink-0 opacity-60" />
          </a>
        );

      default:
        return (
          <p className="whitespace-pre-wrap [overflow-wrap:anywhere]">
            {message.content || '[ข้อความที่ไม่รองรับ]'}
          </p>
        );
    }
  };

  return (
    <>
      {/* Timestamp separator */}
      {showTimestamp && (
        <div className="flex justify-center my-3">
          <span className="text-xs text-muted-foreground bg-muted/50 px-3 py-1 rounded-full">
            {format(new Date(message.created_at), 'PPp', { locale: th })}
          </span>
        </div>
      )}

      {/* Message bubble */}
      <div
        className={cn(
          'flex gap-2 px-3 w-full',
          isUser ? 'justify-end' : 'justify-start',
          senderChanged && 'mt-3'
        )}
      >
        {/* Avatar for non-user messages */}
        {!isUser && (
          <Avatar className="h-8 w-8 flex-shrink-0 bg-[#06C755]/10">
            <AvatarFallback className="bg-[#06C755]/10 text-[#06C755]">
              {message.sender === 'bot' ? (
                <span className="text-xs">Bot</span>
              ) : (
                <Headphones className="h-4 w-4" />
              )}
            </AvatarFallback>
          </Avatar>
        )}

        {/* Message content */}
        <div
          className={cn(
            'max-w-[75%] min-w-0 rounded-2xl px-4 py-2',
            isUser
              ? 'bg-[#06C755] text-white rounded-br-md'
              : 'bg-muted rounded-bl-md'
          )}
        >
          {renderContent()}
        </div>
      </div>

      {/* Image Lightbox */}
      {message.type === 'image' && message.media_url && (
        <Dialog open={lightboxOpen} onOpenChange={setLightboxOpen}>
          <DialogContent className="max-w-[90vw] max-h-[90vh] p-0 border-0 bg-transparent">
            <img
              src={message.media_url}
              alt="รูปภาพเต็มขนาด"
              className="max-w-full max-h-[90vh] object-contain mx-auto"
            />
          </DialogContent>
        </Dialog>
      )}
    </>
  );
});
