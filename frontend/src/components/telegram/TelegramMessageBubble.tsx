import { memo, useState } from 'react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
} from '@/components/ui/dialog';
import { LazyImage } from '@/components/ui/lazy-image';
import {
  User,
  Headphones,
  MapPin,
  FileIcon,
  Play,
  Download,
  ExternalLink,
} from 'lucide-react';
import { format } from 'date-fns';
import { th } from 'date-fns/locale';
import { cn, toSafeArray } from '@/lib/utils';
import type { Message } from '@/types/api';

interface TelegramMessageBubbleProps {
  message: Message;
  previousMessage?: Message;
}

// Memoized message bubble
export const TelegramMessageBubble = memo(function TelegramMessageBubble({
  message,
  previousMessage,
}: TelegramMessageBubbleProps) {
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
          <p className="whitespace-pre-wrap break-words">{message.content}</p>
        );

      case 'image':
      case 'photo':
        return (
          <div className="max-w-[280px]">
            {message.media_url ? (
              <>
                <LazyImage
                  src={message.media_url}
                  alt="Photo"
                  className="rounded-lg cursor-pointer hover:opacity-90 transition-opacity w-full"
                  placeholderClassName="min-h-[150px] w-full"
                  onClick={() => setLightboxOpen(true)}
                />
                {message.content && message.content !== '[Photo]' && (
                  <p className="mt-2 text-sm whitespace-pre-wrap">{message.content}</p>
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
                  Your browser does not support video.
                </video>
                {message.content && message.content !== '[Video]' && (
                  <p className="mt-2 text-sm whitespace-pre-wrap">{message.content}</p>
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

      case 'voice':
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
              <p className="text-muted-foreground text-sm">
                {message.type === 'voice' ? 'เสียง' : 'ไฟล์เสียง'}
              </p>
            )}
          </div>
        );

      case 'file': {
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
            <div className="h-10 w-10 rounded-lg bg-[#0088CC]/10 flex items-center justify-center flex-shrink-0">
              <FileIcon className="h-5 w-5 text-[#0088CC]" />
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
      }

      case 'sticker':
        return (
          <div className="max-w-[128px]">
            {message.media_url ? (
              <LazyImage
                src={message.media_url}
                alt={`Sticker ${(metadata?.emoji as string) || ''}`}
                className="max-w-[128px] max-h-[128px]"
                placeholderClassName="min-h-[80px] w-[80px]"
              />
            ) : (
              <p className="text-2xl">{(metadata?.emoji as string) || '🎭'}</p>
            )}
          </div>
        );

      case 'location': {
        const lat = metadata?.latitude as number;
        const lng = metadata?.longitude as number;
        const title = metadata?.title as string;
        const address = metadata?.address as string;
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
                {title || 'ตำแหน่งที่แชร์'}
              </p>
              {address && (
                <p className="text-xs text-muted-foreground truncate">{address}</p>
              )}
            </div>
            <ExternalLink className="h-4 w-4 flex-shrink-0 opacity-60" />
          </a>
        );
      }

      case 'contact': {
        const contactName = [
          metadata?.first_name as string,
          metadata?.last_name as string,
        ].filter(Boolean).join(' ') || 'Contact';
        const phone = metadata?.phone as string;

        return (
          <div className={cn(
            'p-3 rounded-lg min-w-[200px]',
            isUser ? 'bg-muted/50' : 'bg-background/10'
          )}>
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-full bg-[#0088CC]/10 flex items-center justify-center flex-shrink-0">
                <User className="h-5 w-5 text-[#0088CC]" />
              </div>
              <div>
                <p className="font-medium text-sm">{contactName}</p>
                {phone && (
                  <p className="text-xs text-muted-foreground">{phone}</p>
                )}
              </div>
            </div>
          </div>
        );
      }

      case 'poll': {
        const question = metadata?.question as string;
        const options = toSafeArray<string>(metadata?.options);

        return (
          <div className={cn(
            'p-3 rounded-lg min-w-[220px]',
            isUser ? 'bg-muted/50' : 'bg-background/10'
          )}>
            <p className="font-medium text-sm mb-2">{question || 'Poll'}</p>
            <div className="space-y-1">
              {options.map((opt, i) => (
                <div
                  key={i}
                  className="text-xs px-2 py-1 bg-background/20 rounded"
                >
                  {opt}
                </div>
              ))}
            </div>
          </div>
        );
      }

      default:
        return (
          <p className="whitespace-pre-wrap break-words">
            {message.content || '[Unsupported message type]'}
          </p>
        );
    }
  };

  return (
    <>
      {/* Timestamp separator */}
      {showTimestamp && (
        <div className="text-center text-xs text-muted-foreground py-2">
          {format(new Date(message.created_at), 'HH:mm', { locale: th })}
        </div>
      )}

      {/* Sender change indicator */}
      {senderChanged && !showTimestamp && <div className="h-2" />}

      <div className={cn('flex gap-2', isUser ? 'justify-start' : 'justify-end')}>
        {/* User avatar */}
        {isUser && (
          <Avatar className="h-8 w-8 shrink-0 bg-[#0088CC]/10">
            <AvatarFallback className="bg-[#0088CC]/10 text-[#0088CC]">
              <User className="h-4 w-4" />
            </AvatarFallback>
          </Avatar>
        )}

        {/* Message bubble */}
        <div
          className={cn(
            'max-w-[85%] sm:max-w-[70%] rounded-lg px-3 sm:px-4 py-2 break-words overflow-hidden',
            isUser
              ? 'bg-muted text-foreground'
              : 'bg-[#0088CC] text-white'
          )}
        >
          {/* Sender label for agent messages */}
          {message.sender === 'agent' && (
            <div className="flex items-center gap-1 text-xs opacity-70 mb-1">
              <Headphones className="h-3 w-3" />
              <span>แอดมิน</span>
            </div>
          )}

          {/* Message content */}
          {renderContent()}
        </div>

        {/* Agent avatar */}
        {!isUser && (
          <Avatar className="h-8 w-8 shrink-0">
            <AvatarFallback className="bg-[#0088CC] text-white">
              <Headphones className="h-4 w-4" />
            </AvatarFallback>
          </Avatar>
        )}
      </div>

      {/* Lightbox for images */}
      <Dialog open={lightboxOpen} onOpenChange={setLightboxOpen}>
        <DialogContent className="max-w-4xl p-0 bg-black/90 border-0">
          <div className="flex items-center justify-center min-h-[50vh]">
            {message.media_url && (
              <img
                src={message.media_url}
                alt="Photo"
                className="max-w-full max-h-[80vh] object-contain"
              />
            )}
          </div>
          <div className="absolute bottom-4 right-4">
            <Button
              variant="secondary"
              size="sm"
              asChild
            >
              <a
                href={message.media_url || '#'}
                download
                target="_blank"
                rel="noopener noreferrer"
              >
                <Download className="h-4 w-4 mr-2" />
                ดาวน์โหลด
              </a>
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
});

// Helper function to format file size
function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
