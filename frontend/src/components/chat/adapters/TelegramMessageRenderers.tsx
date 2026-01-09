/**
 * T048: Telegram Message Renderers
 * Extracted from TelegramAdapter for better maintainability
 */
import type { ReactNode } from 'react';
import { LazyImage } from '@/components/ui/lazy-image';
import {
  MapPin,
  FileIcon,
  Play,
  Download,
  ExternalLink,
  User,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Message } from '@/types/api';

// Telegram brand color
export const TELEGRAM_BLUE = '#0088CC';

/** Format file size to human readable string */
export function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/** Render Telegram sticker */
export function renderSticker(message: Message): ReactNode {
  const metadata = message.media_metadata as Record<string, unknown> | null;

  if (message.media_url) {
    return (
      <LazyImage
        src={message.media_url}
        alt={`Sticker ${(metadata?.emoji as string) || ''}`}
        className="max-w-[128px] max-h-[128px]"
        placeholderClassName="min-h-[80px] w-[80px]"
      />
    );
  }

  return <p className="text-2xl">{(metadata?.emoji as string) || ''}</p>;
}

/** Render Telegram photo */
export function renderPhoto(
  message: Message,
  onImageClick?: (url: string) => void
): ReactNode {
  if (!message.media_url) {
    return (
      <div className="bg-muted/50 rounded-lg p-4 text-center text-muted-foreground">
        <FileIcon className="h-8 w-8 mx-auto mb-2" />
        <p className="text-sm">Image unavailable</p>
      </div>
    );
  }

  return (
    <div className="max-w-[280px]">
      <LazyImage
        src={message.media_url}
        alt="Photo"
        className="rounded-lg cursor-pointer hover:opacity-90 transition-opacity w-full"
        placeholderClassName="min-h-[150px] w-full"
        onClick={() => onImageClick?.(message.media_url!)}
      />
      {message.content && message.content !== '[Photo]' && (
        <p className="mt-2 text-sm whitespace-pre-wrap">{message.content}</p>
      )}
    </div>
  );
}

/** Render Telegram video */
export function renderVideo(message: Message): ReactNode {
  if (!message.media_url) {
    return (
      <div className="bg-muted/50 rounded-lg p-4 text-center text-muted-foreground">
        <Play className="h-8 w-8 mx-auto mb-2" />
        <p className="text-sm">Video unavailable</p>
      </div>
    );
  }

  return (
    <div className="max-w-[280px]">
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
    </div>
  );
}

/** Render Telegram voice/audio message */
export function renderVoice(message: Message): ReactNode {
  if (!message.media_url) {
    return (
      <p className="text-muted-foreground text-sm">
        {message.type === 'voice' ? 'Voice message' : 'Audio file'}
      </p>
    );
  }

  return (
    <div className="flex items-center gap-2 min-w-[200px]">
      <audio src={message.media_url} controls className="w-full max-w-[250px]" />
    </div>
  );
}

/** Render Telegram file/document */
export function renderFile(message: Message, isUser: boolean): ReactNode {
  const metadata = message.media_metadata as Record<string, unknown> | null;
  const fileName = (metadata?.file_name as string) || 'File';

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

/** Render Telegram location */
export function renderLocation(message: Message, isUser: boolean): ReactNode {
  const metadata = message.media_metadata as Record<string, unknown> | null;
  const lat = metadata?.latitude as number;
  const lng = metadata?.longitude as number;
  const title = metadata?.title as string;
  const address = metadata?.address as string;
  const mapsUrl = lat && lng ? `https://www.google.com/maps?q=${lat},${lng}` : '#';

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
        <p className="font-medium text-sm truncate">{title || 'Shared location'}</p>
        {address && <p className="text-xs text-muted-foreground truncate">{address}</p>}
      </div>
      <ExternalLink className="h-4 w-4 flex-shrink-0 opacity-60" />
    </a>
  );
}

/** Render Telegram contact */
export function renderContact(message: Message, isUser: boolean): ReactNode {
  const metadata = message.media_metadata as Record<string, unknown> | null;
  const contactName = [metadata?.first_name as string, metadata?.last_name as string]
    .filter(Boolean)
    .join(' ') || 'Contact';
  const phone = metadata?.phone as string;

  return (
    <div
      className={cn(
        'p-3 rounded-lg min-w-[200px]',
        isUser ? 'bg-muted/50' : 'bg-background/10'
      )}
    >
      <div className="flex items-center gap-3">
        <div className="h-10 w-10 rounded-full bg-[#0088CC]/10 flex items-center justify-center flex-shrink-0">
          <User className="h-5 w-5 text-[#0088CC]" />
        </div>
        <div>
          <p className="font-medium text-sm">{contactName}</p>
          {phone && <p className="text-xs text-muted-foreground">{phone}</p>}
        </div>
      </div>
    </div>
  );
}

/** Render Telegram poll */
export function renderPoll(message: Message, isUser: boolean): ReactNode {
  const metadata = message.media_metadata as Record<string, unknown> | null;
  const question = metadata?.question as string;
  const options = (metadata?.options as string[]) || [];

  return (
    <div
      className={cn(
        'p-3 rounded-lg min-w-[220px]',
        isUser ? 'bg-muted/50' : 'bg-background/10'
      )}
    >
      <p className="font-medium text-sm mb-2">{question || 'Poll'}</p>
      <div className="space-y-1">
        {options.map((opt, i) => (
          <div key={i} className="text-xs px-2 py-1 bg-background/20 rounded">
            {opt}
          </div>
        ))}
      </div>
    </div>
  );
}
