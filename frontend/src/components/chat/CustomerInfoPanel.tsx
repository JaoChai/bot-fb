/**
 * T036: CustomerInfoPanel - Refactored container component
 * Composes: CustomerDetails, BotControl, ConversationTags, ConversationNotes
 */
import { Separator } from '@/components/ui/separator';
import { CustomerDetails } from './CustomerDetails';
import { BotControl } from './BotControl';
import { ConversationTags } from './ConversationTags';
import { ConversationNotes } from './ConversationNotes';
import type { Conversation } from '@/types/api';

interface CustomerInfoPanelProps {
  botId: number;
  conversation: Conversation;
}

export function CustomerInfoPanel({ botId, conversation }: CustomerInfoPanelProps) {
  return (
    <div className="p-4 space-y-6">
      {/* Customer Profile & Stats */}
      <CustomerDetails conversation={conversation} />

      <Separator />

      {/* Bot Control / Human Agent Mode */}
      <BotControl botId={botId} conversation={conversation} />

      <Separator />

      {/* Tags Panel */}
      <ConversationTags
        botId={botId}
        conversationId={conversation.id}
        currentTags={conversation.tags || []}
      />

      <Separator />

      {/* Notes Panel */}
      <ConversationNotes botId={botId} conversationId={conversation.id} />
    </div>
  );
}
