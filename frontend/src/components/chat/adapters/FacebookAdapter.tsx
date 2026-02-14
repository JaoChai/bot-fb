/**
 * T049: Facebook Channel Adapter
 * Facebook Messenger-specific message rendering and handling
 */
import type { ReactNode } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { LazyImage } from '@/components/ui/lazy-image';
import {
  FileIcon,
  Play,
  Download,
  User,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Message } from '@/types/api';
import type { ChannelAdapter, MediaUploadConfig, OutgoingPayload } from './ChannelAdapter';

// Facebook Messenger brand color
const FACEBOOK_BLUE = '#0084FF';

/**
 * Helper function to format file size
 */
function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

/**
 * Render Facebook image
 */
function renderImage(
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
 * Render Facebook video
 */
function renderVideo(message: Message): ReactNode {
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
 * Render Facebook audio
 */
function renderAudio(message: Message): ReactNode {
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
 * Render Facebook file/attachment
 */
function renderFile(message: Message, isUser: boolean): ReactNode {
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
      <div className="h-10 w-10 rounded-lg bg-[#0084FF]/10 flex items-center justify-center flex-shrink-0">
        <FileIcon className="h-5 w-5 text-[#0084FF]" />
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
 * Render Facebook sticker (GIF-like stickers)
 */
function renderSticker(message: Message): ReactNode {
  if (message.media_url) {
    return (
      <img
        src={message.media_url}
        alt="Sticker"
        className="max-w-[120px] max-h-[120px]"
        loading="lazy"
      />
    );
  }
  return <p className="text-2xl">{message.content || ''}</p>;
}

/**
 * Facebook Channel Adapter implementation
 */
export const facebookAdapter: ChannelAdapter = {
  name: 'facebook',
  brandColor: FACEBOOK_BLUE,

  renderMessageContent: (message, options) => {
    const type = message.type || 'text';
    const isUser = message.sender === 'user';

    switch (type) {
      case 'sticker':
        return renderSticker(message);

      case 'image':
      case 'photo':
        return renderImage(message, options?.onImageClick);

      case 'video':
        return renderVideo(message);

      case 'audio':
        return renderAudio(message);

      case 'file':
        return renderFile(message, isUser);

      case 'text':
      default:
        return (
          <p className="whitespace-pre-wrap break-words">
            {message.content || '[Unsupported message type]'}
          </p>
        );
    }
  },

  renderAvatar: (customer, size = 'md') => {
    const sizeClass = {
      sm: 'h-6 w-6',
      md: 'h-8 w-8',
      lg: 'h-10 w-10',
    }[size];

    return (
      <Avatar className={cn(sizeClass, 'flex-shrink-0')}>
        {customer?.picture_url ? (
          <AvatarImage src={customer.picture_url} alt={customer.display_name || 'User'} />
        ) : null}
        <AvatarFallback className="bg-[#0084FF]/10 text-[#0084FF]">
          <User className="h-4 w-4" />
        </AvatarFallback>
      </Avatar>
    );
  },

  getMediaUploadConfig: (): MediaUploadConfig => ({
    maxSize: 25 * 1024 * 1024, // 25MB for Facebook
    formats: ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'pdf'],
  }),

  formatOutgoingMessage: (content, type = 'text'): OutgoingPayload => ({
    type,
    content,
    metadata: {
      channel: 'facebook',
    },
  }),

  supportsFeature: (feature) => {
    // Facebook supports basic features
    const supported = ['sticker', 'quickReply'];
    return supported.includes(feature);
  },
};

