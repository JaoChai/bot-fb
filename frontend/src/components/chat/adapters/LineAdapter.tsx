/**
 * T047: LINE Channel Adapter
 * LINE-specific message rendering and handling
 */
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { User } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ChannelAdapter, MediaUploadConfig, OutgoingPayload } from './ChannelAdapter';
import {
  LINE_GREEN,
  renderSticker,
  renderImage,
  renderVideo,
  renderAudio,
  renderFile,
  renderLocation,
} from './LineMessageRenderers';

/**
 * LINE Channel Adapter implementation
 */
export const lineAdapter: ChannelAdapter = {
  name: 'line',
  brandColor: LINE_GREEN,

  renderMessageContent: (message, options) => {
    const type = message.type || 'text';
    const isUser = message.sender === 'user';

    switch (type) {
      case 'sticker':
        return renderSticker(message);

      case 'image':
        return renderImage(message, options?.onImageClick);

      case 'video':
        return renderVideo(message);

      case 'audio':
        return renderAudio(message);

      case 'file':
        return renderFile(message, isUser);

      case 'location':
        return renderLocation(message, isUser);

      case 'text':
      default:
        return (
          <p className="whitespace-pre-wrap break-words">{message.content}</p>
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
        <AvatarFallback className="bg-[#06C755]/10 text-[#06C755]">
          <User className="h-4 w-4" />
        </AvatarFallback>
      </Avatar>
    );
  },

  getMediaUploadConfig: (): MediaUploadConfig => ({
    maxSize: 300 * 1024 * 1024, // 300MB for LINE
    formats: ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'm4a', 'pdf'],
    maxDuration: 60, // 1 minute for video
  }),

  formatOutgoingMessage: (content, type = 'text'): OutgoingPayload => ({
    type,
    content,
    metadata: {
      channel: 'line',
    },
  }),

  supportsFeature: (feature) => {
    const supported = ['sticker', 'location', 'quickReply'];
    return supported.includes(feature);
  },
};

