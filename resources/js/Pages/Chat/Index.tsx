import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Badge } from '@/Components/ui/badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/Components/ui/avatar';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { ChannelIcon } from '@/Components/ui/channel-icon';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import {
  Loader2,
  MessageSquare,
  Search,
  Send,
  User,
  ChevronLeft,
  ChevronDown,
  Bot,
  UserCircle,
  Wifi,
  WifiOff,
  Hand,
} from 'lucide-react';
import { cn } from '@/Lib/utils';
import { useEcho, useEchoConnection } from '@/Hooks/useEcho';
import { useChannelAdapter } from '@/Components/chat/adapters';
import type { SharedProps, Bot as BotType, Conversation, Message, Customer, ChannelType } from '@/types';

interface ConversationWithRelations extends Conversation {
  customer: Customer;
  latest_message?: Message;
  messages_count: number;
  unread_count?: number;
  hitl_mode?: boolean;
}

interface Props extends SharedProps {
  bots: Array<{
    id: number;
    name: string;
    channel_type: ChannelType;
    status: string;
  }>;
  selectedBotId: number | null;
  conversations: {
    data: ConversationWithRelations[];
    meta: {
      current_page: number;
      last_page: number;
      per_page: number;
      total: number;
    };
    links: {
      next: string | null;
      prev: string | null;
    };
  } | null;
  selectedConversation?: ConversationWithRelations | null;
  messages?: {
    data: Message[];
    current_page: number;
    last_page: number;
  } | null;
  filters: {
    search?: string;
    status?: string;
  };
}

export default function ChatIndex() {
  const { bots, selectedBotId, conversations, selectedConversation, messages, filters, flash } = usePage<Props>().props;

  // Local state
  const [searchQuery, setSearchQuery] = useState(filters.search || '');
  const [messageInput, setMessageInput] = useState('');
  const [isSending, setIsSending] = useState(false);
  const [showMobileChat, setShowMobileChat] = useState(false);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [isConnected, setIsConnected] = useState(true);
  const [isTogglingHitl, setIsTogglingHitl] = useState(false);
  const scrollAreaRef = useRef<HTMLDivElement>(null);
  const lastMessageCountRef = useRef<number>(0);

  // Get the selected bot
  const selectedBot = bots.find(b => b.id === selectedBotId);

  // Get channel adapter for message rendering
  const channelAdapter = useChannelAdapter(selectedConversation?.customer?.channel_type);

  // Echo connection status
  useEchoConnection({
    onConnected: () => setIsConnected(true),
    onReconnected: () => setIsConnected(true),
    onDisconnected: () => setIsConnected(false),
  });

  // Subscribe to bot channel for new messages (real-time updates)
  useEcho({
    channel: selectedBotId ? `bot.${selectedBotId}` : '',
    event: 'NewMessage',
    reloadOnly: ['conversations', 'messages'],
    debug: false,
  });

  // Subscribe to conversation updates
  useEcho({
    channel: selectedConversation ? `conversation.${selectedConversation.id}` : '',
    event: 'MessageSent',
    reloadOnly: ['messages'],
    debug: false,
  });

  // Scroll to bottom when new messages arrive
  useEffect(() => {
    const messageCount = messages?.data?.length ?? 0;

    // Only scroll if there are new messages (not on every render)
    if (messageCount > 0 && messageCount !== lastMessageCountRef.current) {
      lastMessageCountRef.current = messageCount;

      // Use requestAnimationFrame to ensure DOM is ready
      requestAnimationFrame(() => {
        if (scrollAreaRef.current) {
          scrollAreaRef.current.scrollTop = scrollAreaRef.current.scrollHeight;
        }
      });
    }
  }, [messages?.data?.length]);

  // Toggle HITL mode
  const handleToggleHitl = useCallback(async () => {
    if (!selectedConversation || isTogglingHitl) return;

    setIsTogglingHitl(true);
    try {
      const response = await fetch(`/chat/conversations/${selectedConversation.id}/hitl`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ hitl_mode: !selectedConversation.hitl_mode }),
      });

      if (response.ok) {
        router.reload({ only: ['selectedConversation'] });
      }
    } finally {
      setIsTogglingHitl(false);
    }
  }, [selectedConversation, isTogglingHitl]);

  // Handle bot selection
  const handleBotSelect = useCallback((botId: string) => {
    router.get('/chat', { botId }, {
      preserveState: true,
      preserveScroll: true,
    });
  }, []);

  // Handle search
  const handleSearch = useCallback((e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedBotId) return;

    router.get('/chat', {
      botId: selectedBotId,
      search: searchQuery || undefined,
    }, {
      preserveState: true,
    });
  }, [selectedBotId, searchQuery]);

  // Handle conversation selection
  const handleConversationSelect = useCallback((conversation: ConversationWithRelations) => {
    router.get(`/chat/conversations/${conversation.id}`, {}, {
      preserveState: true,
    });
    setShowMobileChat(true);
  }, []);

  // Load more conversations (infinite scroll)
  const handleLoadMoreConversations = useCallback(() => {
    if (!conversations?.links.next || isLoadingMore) return;

    setIsLoadingMore(true);
    router.get(conversations.links.next, {}, {
      preserveState: true,
      preserveScroll: true,
      onFinish: () => setIsLoadingMore(false),
    });
  }, [conversations, isLoadingMore]);

  // Send message
  const handleSendMessage = useCallback(async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedConversation || !messageInput.trim() || isSending) return;

    setIsSending(true);

    try {
      const response = await fetch(`/chat/conversations/${selectedConversation.id}/send`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '',
        },
        body: JSON.stringify({ content: messageInput }),
      });

      if (response.ok) {
        setMessageInput('');
        // Reload the page to get updated messages
        router.reload({ only: ['messages', 'conversations'] });
      }
    } finally {
      setIsSending(false);
    }
  }, [selectedConversation, messageInput, isSending]);

  // Format time
  const formatTime = (dateString: string) => {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'เมื่อกี้';
    if (diffMins < 60) return `${diffMins} นาที`;
    if (diffHours < 24) return `${diffHours} ชม.`;
    if (diffDays < 7) return `${diffDays} วัน`;
    return date.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
  };

  return (
    <AuthenticatedLayout header="Live Chat">
      <Head title="Live Chat" />

      <div className="h-[calc(100vh-8rem)] flex">
        {/* Sidebar - Conversation List */}
        <div className={cn(
          "w-80 border-r flex flex-col bg-background",
          showMobileChat && "hidden md:flex"
        )}>
          {/* Bot Selector */}
          <div className="p-4 border-b">
            <Select value={selectedBotId?.toString() || ''} onValueChange={handleBotSelect}>
              <SelectTrigger>
                <SelectValue placeholder="เลือกบอท" />
              </SelectTrigger>
              <SelectContent>
                {bots.map((bot) => (
                  <SelectItem key={bot.id} value={bot.id.toString()}>
                    <div className="flex items-center gap-2">
                      <ChannelIcon channel={bot.channel_type} className="h-4 w-4" />
                      <span>{bot.name}</span>
                    </div>
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Search */}
          <form onSubmit={handleSearch} className="p-4 border-b">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                placeholder="ค้นหาการสนทนา..."
                className="pl-9"
              />
            </div>
          </form>

          {/* Conversation List */}
          <ScrollArea className="flex-1">
            {!selectedBotId ? (
              <div className="p-8 text-center text-muted-foreground">
                <MessageSquare className="h-12 w-12 mx-auto mb-4 opacity-50" />
                <p>เลือกบอทเพื่อดูการสนทนา</p>
              </div>
            ) : !conversations?.data.length ? (
              <div className="p-8 text-center text-muted-foreground">
                <MessageSquare className="h-12 w-12 mx-auto mb-4 opacity-50" />
                <p>ไม่มีการสนทนา</p>
              </div>
            ) : (
              <div>
                {conversations.data.map((conv) => (
                  <button
                    key={conv.id}
                    onClick={() => handleConversationSelect(conv)}
                    className={cn(
                      "w-full p-4 text-left hover:bg-muted/50 transition-colors border-b",
                      selectedConversation?.id === conv.id && "bg-muted"
                    )}
                  >
                    <div className="flex items-center gap-3">
                      <Avatar className="h-10 w-10">
                        <AvatarImage src={conv.customer?.picture_url || undefined} />
                        <AvatarFallback>
                          <User className="h-5 w-5" />
                        </AvatarFallback>
                      </Avatar>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center justify-between">
                          <span className="font-medium truncate">
                            {conv.customer?.display_name || 'ลูกค้า'}
                          </span>
                          <span className="text-xs text-muted-foreground">
                            {conv.last_message_at && formatTime(conv.last_message_at)}
                          </span>
                        </div>
                        <div className="flex items-center gap-2 mt-0.5">
                          <p className="text-sm text-muted-foreground truncate">
                            {conv.latest_message?.content || 'ไม่มีข้อความ'}
                          </p>
                          {conv.unread_count && conv.unread_count > 0 && (
                            <Badge variant="destructive" className="h-5 min-w-[1.25rem] px-1.5 text-xs">
                              {conv.unread_count}
                            </Badge>
                          )}
                        </div>
                      </div>
                    </div>
                  </button>
                ))}

                {/* Load More Button */}
                {conversations.links.next && (
                  <div className="p-4 text-center">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={handleLoadMoreConversations}
                      disabled={isLoadingMore}
                    >
                      {isLoadingMore ? (
                        <Loader2 className="h-4 w-4 animate-spin mr-2" />
                      ) : (
                        <ChevronDown className="h-4 w-4 mr-2" />
                      )}
                      โหลดเพิ่มเติม
                    </Button>
                  </div>
                )}
              </div>
            )}
          </ScrollArea>
        </div>

        {/* Main Chat Area */}
        <div className={cn(
          "flex-1 flex flex-col",
          !showMobileChat && "hidden md:flex"
        )}>
          {!selectedConversation ? (
            <div className="flex-1 flex items-center justify-center text-muted-foreground">
              <div className="text-center">
                <MessageSquare className="h-16 w-16 mx-auto mb-4 opacity-50" />
                <p className="text-lg">เลือกการสนทนาเพื่อเริ่มแชท</p>
              </div>
            </div>
          ) : (
            <>
              {/* Chat Header */}
              <div className="h-16 border-b flex items-center px-4 gap-4">
                <Button
                  variant="ghost"
                  size="sm"
                  className="md:hidden"
                  onClick={() => setShowMobileChat(false)}
                >
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                <Avatar className="h-10 w-10">
                  <AvatarImage src={selectedConversation.customer?.picture_url || undefined} />
                  <AvatarFallback>
                    <User className="h-5 w-5" />
                  </AvatarFallback>
                </Avatar>
                <div className="flex-1">
                  <h2 className="font-medium">
                    {selectedConversation.customer?.display_name || 'ลูกค้า'}
                  </h2>
                  <p className="text-xs text-muted-foreground flex items-center gap-1">
                    <ChannelIcon channel={selectedConversation.customer?.channel_type || 'line'} className="h-3 w-3" />
                    {selectedConversation.customer?.platform_id}
                  </p>
                </div>

                {/* Connection Status */}
                <div className="flex items-center gap-2">
                  {isConnected ? (
                    <Wifi className="h-4 w-4 text-green-500" />
                  ) : (
                    <WifiOff className="h-4 w-4 text-red-500" />
                  )}
                </div>

                {/* HITL Toggle */}
                <Button
                  variant={selectedConversation.hitl_mode ? "default" : "outline"}
                  size="sm"
                  onClick={handleToggleHitl}
                  disabled={isTogglingHitl}
                  title={selectedConversation.hitl_mode ? 'ปิด Human-in-the-loop' : 'เปิด Human-in-the-loop'}
                >
                  {isTogglingHitl ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <Hand className="h-4 w-4" />
                  )}
                  <span className="ml-2 hidden sm:inline">
                    {selectedConversation.hitl_mode ? 'HITL เปิด' : 'HITL ปิด'}
                  </span>
                </Button>
              </div>

              {/* Messages */}
              <ScrollArea className="flex-1 p-4" viewportRef={scrollAreaRef}>
                <div className="space-y-4">
                  {messages?.data.slice().reverse().map((message) => (
                    <div
                      key={message.id}
                      className={cn(
                        "flex gap-2 max-w-[80%]",
                        message.sender_type === 'customer' ? "mr-auto" : "ml-auto flex-row-reverse"
                      )}
                    >
                      <Avatar className="h-8 w-8 flex-shrink-0">
                        <AvatarFallback>
                          {message.sender_type === 'customer' ? (
                            <UserCircle className="h-4 w-4" />
                          ) : message.sender_type === 'bot' ? (
                            <Bot className="h-4 w-4" />
                          ) : (
                            <User className="h-4 w-4" />
                          )}
                        </AvatarFallback>
                      </Avatar>
                      <div
                        className={cn(
                          "rounded-lg px-4 py-2",
                          message.sender_type === 'customer'
                            ? "bg-muted"
                            : message.sender_type === 'bot'
                              ? "bg-blue-100 dark:bg-blue-900"
                              : "bg-primary text-primary-foreground"
                        )}
                      >
                        {/* Use channel adapter for rich message rendering */}
                        {channelAdapter.renderMessageContent(message as any)}
                        <p className="text-[10px] opacity-70 mt-1">
                          {new Date(message.created_at).toLocaleTimeString('th-TH', {
                            hour: '2-digit',
                            minute: '2-digit',
                          })}
                        </p>
                      </div>
                    </div>
                  ))}
                </div>
              </ScrollArea>

              {/* Message Input */}
              <form onSubmit={handleSendMessage} className="h-16 border-t flex items-center px-4 gap-2">
                <Input
                  value={messageInput}
                  onChange={(e) => setMessageInput(e.target.value)}
                  placeholder="พิมพ์ข้อความ..."
                  className="flex-1"
                />
                <Button type="submit" disabled={isSending || !messageInput.trim()}>
                  {isSending ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    <Send className="h-4 w-4" />
                  )}
                </Button>
              </form>
            </>
          )}
        </div>
      </div>
    </AuthenticatedLayout>
  );
}
