import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Search, Loader2, MessageCircle, Bot, Headphones } from 'lucide-react';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';
import { cn } from '@/lib/utils';
import type { Conversation, ConversationStatusCounts } from '@/types/api';

const channelColors: Record<string, string> = {
  line: 'text-[#06C755]',
  facebook: 'text-[#0084FF]',
  demo: 'text-destructive',
};

interface ConversationListProps {
  conversations: Conversation[];
  selectedId: number | null;
  onSelect: (conversation: Conversation) => void;
  isLoading: boolean;
  statusFilter: string;
  onStatusFilterChange: (status: string) => void;
  search: string;
  onSearchChange: (search: string) => void;
  statusCounts?: ConversationStatusCounts;
}

export function ConversationList({
  conversations,
  selectedId,
  onSelect,
  isLoading,
  statusFilter,
  onStatusFilterChange,
  search,
  onSearchChange,
  statusCounts,
}: ConversationListProps) {
  return (
    <div className="flex-1 flex flex-col min-h-0">
      {/* Search */}
      <div className="p-3 border-b">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Search..."
            value={search}
            onChange={(e) => onSearchChange(e.target.value)}
            className="pl-9"
          />
        </div>
      </div>

      {/* Status Tabs */}
      <div className="p-2 border-b">
        <Tabs value={statusFilter} onValueChange={onStatusFilterChange}>
          <TabsList className="w-full grid grid-cols-3 h-8">
            <TabsTrigger value="all" className="text-xs h-7">
              All
              {statusCounts && (
                <Badge variant="secondary" className="ml-1 text-xs px-1 py-0 h-4">
                  {statusCounts.total}
                </Badge>
              )}
            </TabsTrigger>
            <TabsTrigger value="active" className="text-xs h-7">
              Active
              {statusCounts && (
                <Badge variant="secondary" className="ml-1 text-xs px-1 py-0 h-4">
                  {statusCounts.active}
                </Badge>
              )}
            </TabsTrigger>
            <TabsTrigger value="handover" className="text-xs h-7">
              Handover
              {statusCounts && (
                <Badge variant="secondary" className="ml-1 text-xs px-1 py-0 h-4">
                  {statusCounts.handover}
                </Badge>
              )}
            </TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {/* Conversation List */}
      <ScrollArea className="flex-1">
        {isLoading ? (
          <div className="flex items-center justify-center py-8">
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        ) : conversations.length === 0 ? (
          <div className="text-center py-8 text-muted-foreground text-sm">
            No conversations found
          </div>
        ) : (
          <div className="p-2 space-y-1">
            {conversations.map((conversation) => (
              <ConversationItem
                key={conversation.id}
                conversation={conversation}
                isSelected={conversation.id === selectedId}
                onSelect={() => onSelect(conversation)}
              />
            ))}
          </div>
        )}
      </ScrollArea>
    </div>
  );
}

interface ConversationItemProps {
  conversation: Conversation;
  isSelected: boolean;
  onSelect: () => void;
}

function ConversationItem({ conversation, isSelected, onSelect }: ConversationItemProps) {
  const customerName = conversation.customer_profile?.display_name || 'Unknown';
  const customerInitial = customerName.charAt(0).toUpperCase();
  const hasUnread = conversation.unread_count > 0;
  const lastMessageTime = conversation.last_message_at
    ? formatDistanceToNow(new Date(conversation.last_message_at), { addSuffix: false, locale: th })
    : null;

  return (
    <button
      onClick={onSelect}
      className={cn(
        'w-full p-3 rounded-lg flex items-start gap-3 text-left transition-colors cursor-pointer',
        'hover:bg-accent/50',
        isSelected && 'bg-accent'
      )}
    >
      {/* Avatar with unread indicator */}
      <div className="relative">
        <Avatar className="h-10 w-10">
          <AvatarImage src={conversation.customer_profile?.picture_url || undefined} />
          <AvatarFallback>{customerInitial}</AvatarFallback>
        </Avatar>
        {hasUnread && (
          <span className="absolute -top-0.5 -right-0.5 h-3 w-3 bg-green-500 rounded-full border-2 border-background" />
        )}
      </div>

      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center justify-between gap-2">
          <span className={cn('font-medium truncate', hasUnread && 'font-semibold')}>
            {customerName}
          </span>
          <span className="text-xs text-muted-foreground flex-shrink-0">
            {lastMessageTime}
          </span>
        </div>

        <div className="flex items-center gap-1.5 mt-0.5">
          <MessageCircle
            className={cn(
              'h-3 w-3 flex-shrink-0',
              channelColors[conversation.channel_type] || 'text-muted-foreground'
            )}
          />
          <span className="text-xs text-muted-foreground truncate">
            {conversation.message_count} messages
          </span>
        </div>

        {/* Bot status badge */}
        <div className="flex items-center gap-1.5 mt-1">
          {conversation.is_handover ? (
            <Badge variant="outline" className="text-xs h-5 gap-1 text-amber-600 border-amber-300">
              <Headphones className="h-3 w-3" />
              Handover
            </Badge>
          ) : (
            <Badge variant="outline" className="text-xs h-5 gap-1 text-green-600 border-green-300">
              <Bot className="h-3 w-3" />
              Bot ON
            </Badge>
          )}
          {hasUnread && (
            <Badge className="text-xs h-5 bg-green-500 hover:bg-green-500">
              {conversation.unread_count} new
            </Badge>
          )}
        </div>
      </div>
    </button>
  );
}
