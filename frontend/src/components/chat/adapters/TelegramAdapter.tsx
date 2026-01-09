/**
 * T048: Telegram Channel Adapter
 * Telegram-specific message rendering and handling
 */
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { User, Users } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ChannelAdapter, MediaUploadConfig, OutgoingPayload } from './ChannelAdapter';
import {
  TELEGRAM_BLUE,
  renderSticker,
  renderPhoto,
  renderVideo,
  renderVoice,
  renderFile,
  renderLocation,
  renderContact,
  renderPoll,
} from './TelegramMessageRenderers';

/**
 * Telegram Channel Adapter implementation
 */
export const telegramAdapter: ChannelAdapter = {
  name: 'telegram',
  brandColor: TELEGRAM_BLUE,

  renderMessageContent: (message, options) => {
    const type = message.type || 'text';
    const isUser = message.sender === 'user';

    switch (type) {
      case 'sticker':
        return renderSticker(message);

      case 'image':
      case 'photo':
        return renderPhoto(message, options?.onImageClick);

      case 'video':
        return renderVideo(message);

      case 'voice':
      case 'audio':
        return renderVoice(message);

      case 'file':
        return renderFile(message, isUser);

      case 'location':
        return renderLocation(message, isUser);

      case 'contact':
        return renderContact(message, isUser);

      case 'poll':
        return renderPoll(message, isUser);

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

    // For group chats, show group icon
    const isGroup = customer?.metadata?.chat_type !== 'private';

    return (
      <Avatar className={cn(sizeClass, 'flex-shrink-0 bg-[#0088CC]/10')}>
        {customer?.picture_url ? (
          <AvatarImage src={customer.picture_url} alt={customer.display_name || 'User'} />
        ) : null}
        <AvatarFallback className="bg-[#0088CC]/10 text-[#0088CC]">
          {isGroup ? <Users className="h-4 w-4" /> : <User className="h-4 w-4" />}
        </AvatarFallback>
      </Avatar>
    );
  },

  getMediaUploadConfig: (): MediaUploadConfig => ({
    maxSize: 50 * 1024 * 1024, // 50MB for Telegram
    formats: ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm', 'ogg', 'mp3', 'pdf', 'doc', 'docx', 'zip'],
    maxDuration: 60, // 1 minute for video
  }),

  formatOutgoingMessage: (content, type = 'text'): OutgoingPayload => ({
    type,
    content,
    metadata: {
      channel: 'telegram',
      parse_mode: 'HTML', // Telegram supports HTML formatting
    },
  }),

  supportsFeature: (feature) => {
    // Telegram supports all features
    return ['sticker', 'location', 'voice', 'contact', 'poll'].includes(feature);
  },
};

export default telegramAdapter;
