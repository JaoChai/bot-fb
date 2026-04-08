/**
 * LINE Message Type Renderers
 * Extracted from LineAdapter for modularity
 */
import type { ReactNode } from 'react';
import { LazyImage } from '@/components/ui/lazy-image';
import {
  MapPin,
  FileIcon,
  Play,
  Download,
  ExternalLink,
  Smile,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Message } from '@/types/api';

// LINE brand color
export const LINE_GREEN = '#06C755';

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

/**
 * Render LINE sticker
 */
export function renderSticker(message: Message): ReactNode {
  if (message.media_url) {
    return (
      <img
        src={message.media_url}
        alt="Sticker"
        className="w-full h-auto max-w-[120px]"
        loading="lazy"
      />
    );
  }
  return (
    <div className="flex items-center gap-2 p-2">
      <Smile className="h-8 w-8 text-[#06C755]" />
      <span className="text-muted-foreground text-sm">Sticker</span>
    </div>
  );
}

/**
 * Render LINE image
 */
export function renderImage(
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
        alt="Image"
        className="rounded-lg cursor-pointer hover:opacity-90 transition-opacity w-full"
        placeholderClassName="min-h-[150px] w-full"
        onClick={() => onImageClick?.(message.media_url!)}
      />
      {message.content && !message.content.includes('[') && (
        <p className="mt-2 text-sm whitespace-pre-wrap">{message.content}</p>
      )}
    </div>
  );
}

/**
 * Render LINE video
 */
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
      {message.content && !message.content.includes('[') && (
        <p className="mt-2 text-sm whitespace-pre-wrap">{message.content}</p>
      )}
    </div>
  );
}

/**
 * Render LINE audio
 */
export function renderAudio(message: Message): ReactNode {
  if (!message.media_url) {
    return <p className="text-muted-foreground text-sm">Audio unavailable</p>;
  }

  return (
    <div className="flex items-center gap-2 min-w-[200px]">
      <audio src={message.media_url} controls className="w-full max-w-[250px]" />
    </div>
  );
}

/**
 * Render LINE file
 */
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
        isUser ? 'bg-white/20 hover:bg-white/30' : 'bg-muted/50 hover:bg-muted'
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
}

/**
 * Render LINE location
 */
export function renderLocation(message: Message, isUser: boolean): ReactNode {
  const locationMatch = message.content?.match(
    /\[(?:Location|ตำแหน่ง)\]\s*([^(]*)\s*\(([^,]+),\s*([^)]+)\)/
  );
  const address = locationMatch?.[1]?.trim() || '';
  const lat = parseFloat(locationMatch?.[2] || '0');
  const lng = parseFloat(locationMatch?.[3] || '0');
  const mapsUrl = lat && lng ? `https://www.google.com/maps?q=${lat},${lng}` : '#';

  return (
    <a
      href={mapsUrl}
      target="_blank"
      rel="noopener noreferrer"
      className={cn(
        'flex items-center gap-3 p-3 rounded-lg transition-colors min-w-[200px]',
        isUser ? 'bg-white/20 hover:bg-white/30' : 'bg-muted/50 hover:bg-muted'
      )}
    >
      <div className="h-10 w-10 rounded-lg bg-red-500/10 flex items-center justify-center flex-shrink-0">
        <MapPin className="h-5 w-5 text-red-500" />
      </div>
      <div className="flex-1 min-w-0">
        <p className="font-medium text-sm truncate">{address || 'Shared location'}</p>
        {lat && lng && (
          <p className="text-xs text-muted-foreground truncate">
            {lat.toFixed(6)}, {lng.toFixed(6)}
          </p>
        )}
      </div>
      <ExternalLink className="h-4 w-4 flex-shrink-0 opacity-60" />
    </a>
  );
}
