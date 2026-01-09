/**
 * Hook for chat window actions (send message, toggle handover, clear context)
 * Extracted from ChatWindow.tsx to reduce component size
 */
import { useCallback } from 'react';
import {
  useSendAgentMessage,
  useToggleHandover,
  useClearContext,
} from '@/hooks/useConversations';
import { useToast } from '@/hooks/use-toast';
import type { Conversation } from '@/types/api';
import type { QuickReply } from '@/types/quick-reply';
import { api } from '@/lib/api';

interface UseChatActionsOptions {
  botId: number;
  conversation: Conversation;
}

export function useChatActions({ botId, conversation }: UseChatActionsOptions) {
  const { toast } = useToast();

  // Mutations
  const sendAgentMessage = useSendAgentMessage(botId);
  const toggleHandover = useToggleHandover(botId);
  const clearContext = useClearContext(botId);

  // Handle send message (basic text)
  const handleSendMessage = useCallback(async (content: string) => {
    try {
      const result = await sendAgentMessage.mutateAsync({
        conversationId: conversation.id,
        data: { content },
      });

      if (result.delivery_error) {
        toast({
          title: 'Message saved but delivery failed',
          description: result.delivery_error,
          variant: 'destructive',
        });
      }
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to send message',
        variant: 'destructive',
      });
    }
  }, [sendAgentMessage, conversation.id, toast]);

  // Handle send with media (for channel-specific inputs)
  const handleSendWithMedia = useCallback(async (content: string, media: File | null) => {
    try {
      let mediaUrl: string | undefined;
      let mediaType: 'text' | 'image' | 'video' | 'audio' | 'file' = 'text';

      if (media) {
        const formData = new FormData();
        formData.append('file', media);

        const uploadResponse = await api.post<{ url: string; type: string }>(
          `/bots/${botId}/conversations/${conversation.id}/upload`,
          formData,
          { headers: { 'Content-Type': 'multipart/form-data' } }
        );

        mediaUrl = uploadResponse.data.url;
        mediaType = uploadResponse.data.type as 'image' | 'video' | 'audio' | 'file';
      }

      const result = await sendAgentMessage.mutateAsync({
        conversationId: conversation.id,
        data: {
          content: content || `[${mediaType}]`,
          type: mediaType,
          media_url: mediaUrl,
        },
      });

      if (result.delivery_error) {
        toast({
          title: 'Message saved but delivery failed',
          description: result.delivery_error,
          variant: 'destructive',
        });
      }
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to send message',
        variant: 'destructive',
      });
      throw new Error('Failed to send');
    }
  }, [sendAgentMessage, conversation.id, botId, toast]);

  // Handle toggle handover
  const handleToggleHandover = useCallback(async () => {
    try {
      await toggleHandover.mutateAsync({ conversationId: conversation.id });
      toast({
        title: conversation.is_handover ? 'Bot enabled' : 'Handover mode',
        description: conversation.is_handover
          ? 'Bot will respond to this conversation'
          : 'You can respond directly. Bot will auto-enable in 30 minutes',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to toggle bot mode',
        variant: 'destructive',
      });
    }
  }, [toggleHandover, conversation.id, conversation.is_handover, toast]);

  // Handle clear context
  const handleClearContext = useCallback(async () => {
    try {
      await clearContext.mutateAsync(conversation.id);
      toast({
        title: 'Context reset',
        description: 'Bot will start fresh without referencing previous messages',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to reset context',
        variant: 'destructive',
      });
    }
  }, [clearContext, conversation.id, toast]);

  // Handle quick reply selection
  const handleQuickReplySelect = useCallback(async (quickReply: QuickReply) => {
    await handleSendMessage(quickReply.content);
  }, [handleSendMessage]);

  return {
    // Actions
    handleSendMessage,
    handleSendWithMedia,
    handleToggleHandover,
    handleClearContext,
    handleQuickReplySelect,
    // Loading states
    isSending: sendAgentMessage.isPending,
    isTogglingHandover: toggleHandover.isPending,
    isClearingContext: clearContext.isPending,
  };
}
