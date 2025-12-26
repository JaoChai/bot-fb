import { useState, useMemo } from 'react';
import { Link, useSearchParams, useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useConversations, useCloseConversation, useReopenConversation, useToggleHandover } from '@/hooks/useConversations';
import { useBots } from '@/hooks/useKnowledgeBase';
import { useToast } from '@/hooks/use-toast';
import {
  Loader2,
  MessageSquare,
  Search,
  MoreVertical,
  X,
  RotateCcw,
  UserCheck,
  ChevronLeft,
  ChevronRight,
  ArrowLeft,
  MessageCircle,
  Filter,
  SortAsc,
  SortDesc,
} from 'lucide-react';
import type { Conversation, ConversationFilters } from '@/types/api';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';

const statusColors: Record<string, string> = {
  active: 'bg-green-500',
  closed: 'bg-gray-500',
  handover: 'bg-yellow-500',
};

const channelIcons: Record<string, string> = {
  line: '🟢',
  facebook: '🔵',
  demo: '🔴',
};

export function ConversationsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const navigate = useNavigate();
  const { toast } = useToast();

  // Get botId and filters from URL params
  const botIdParam = searchParams.get('botId');
  const botId = botIdParam ? parseInt(botIdParam, 10) : null;

  // Filter states
  const [search, setSearch] = useState(searchParams.get('search') || '');
  const [statusFilter, setStatusFilter] = useState(searchParams.get('status') || 'all');
  const [sortBy, setSortBy] = useState<ConversationFilters['sort_by']>(
    (searchParams.get('sort_by') as ConversationFilters['sort_by']) || 'last_message_at'
  );
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>(
    (searchParams.get('sort_direction') as 'asc' | 'desc') || 'desc'
  );
  const [page, setPage] = useState(parseInt(searchParams.get('page') || '1', 10));

  // Memoized filters
  const filters = useMemo<ConversationFilters>(() => ({
    status: statusFilter === 'all' ? undefined : statusFilter,
    search: search || undefined,
    sort_by: sortBy,
    sort_direction: sortDirection,
    page,
    per_page: 20,
  }), [statusFilter, search, sortBy, sortDirection, page]);

  // Queries
  const { data: botsResponse, isLoading: isBotsLoading } = useBots();
  const bots = botsResponse?.data || [];
  const selectedBot = bots.find((b) => b.id === botId);

  const {
    data: conversationsResponse,
    isLoading,
    error
  } = useConversations(botId ?? undefined, filters);

  const conversations = conversationsResponse?.data || [];
  const meta = conversationsResponse?.meta;
  const statusCounts = meta?.status_counts;

  // Mutations
  const closeConversation = useCloseConversation(botId ?? undefined);
  const reopenConversation = useReopenConversation(botId ?? undefined);
  const toggleHandover = useToggleHandover(botId ?? undefined);

  // Handlers
  const handleBotSelect = (value: string) => {
    setSearchParams({ botId: value });
    setPage(1);
  };

  const handleSearch = (value: string) => {
    setSearch(value);
    setPage(1);
    updateSearchParams({ search: value, page: '1' });
  };

  const handleStatusFilter = (value: string) => {
    setStatusFilter(value);
    setPage(1);
    updateSearchParams({ status: value, page: '1' });
  };

  const handleSort = (field: NonNullable<ConversationFilters['sort_by']>) => {
    if (sortBy === field) {
      const newDirection = sortDirection === 'desc' ? 'asc' : 'desc';
      setSortDirection(newDirection);
      updateSearchParams({ sort_direction: newDirection });
    } else {
      setSortBy(field);
      setSortDirection('desc');
      updateSearchParams({ sort_by: field, sort_direction: 'desc' });
    }
  };

  const handlePageChange = (newPage: number) => {
    setPage(newPage);
    updateSearchParams({ page: newPage.toString() });
  };

  const updateSearchParams = (updates: Record<string, string>) => {
    const newParams = new URLSearchParams(searchParams);
    Object.entries(updates).forEach(([key, value]) => {
      if (value && value !== 'all') {
        newParams.set(key, value);
      } else {
        newParams.delete(key);
      }
    });
    setSearchParams(newParams);
  };

  const handleClose = async (conversation: Conversation) => {
    try {
      await closeConversation.mutateAsync(conversation.id);
      toast({
        title: 'Conversation closed',
        description: 'The conversation has been closed successfully.',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to close conversation.',
        variant: 'destructive',
      });
    }
  };

  const handleReopen = async (conversation: Conversation) => {
    try {
      await reopenConversation.mutateAsync(conversation.id);
      toast({
        title: 'Conversation reopened',
        description: 'The conversation has been reopened.',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to reopen conversation.',
        variant: 'destructive',
      });
    }
  };

  const handleToggleHandover = async (conversation: Conversation) => {
    try {
      await toggleHandover.mutateAsync({ conversationId: conversation.id });
      toast({
        title: conversation.is_handover ? 'Bot mode enabled' : 'Handover enabled',
        description: conversation.is_handover
          ? 'The bot is now handling this conversation.'
          : 'You are now handling this conversation.',
      });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to toggle handover mode.',
        variant: 'destructive',
      });
    }
  };

  // No bot selected - show bot selector
  if (!botId) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Conversations</h1>
          <p className="text-muted-foreground">Select a bot to view its conversations</p>
        </div>

        {isBotsLoading ? (
          <div className="flex items-center justify-center h-64">
            <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
          </div>
        ) : bots.length === 0 ? (
          <Card>
            <CardHeader className="text-center">
              <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                <MessageSquare className="h-6 w-6 text-muted-foreground" />
              </div>
              <CardTitle>No bots available</CardTitle>
              <CardDescription>Create a bot first to start managing conversations</CardDescription>
            </CardHeader>
            <CardContent className="text-center">
              <Button asChild>
                <Link to="/bots">Go to Bots</Link>
              </Button>
            </CardContent>
          </Card>
        ) : (
          <Card className="max-w-md">
            <CardHeader>
              <CardTitle>Select a Bot</CardTitle>
              <CardDescription>Choose which bot's conversations you want to view</CardDescription>
            </CardHeader>
            <CardContent>
              <Select onValueChange={handleBotSelect}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a bot..." />
                </SelectTrigger>
                <SelectContent>
                  {bots.map((bot) => (
                    <SelectItem key={bot.id} value={bot.id.toString()}>
                      {bot.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </CardContent>
          </Card>
        )}
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <Button variant="ghost" size="icon" onClick={() => navigate('/bots')}>
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-2xl font-bold tracking-tight">Conversations</h1>
              {selectedBot && (
                <Badge variant="outline" className="font-normal">
                  {selectedBot.name}
                </Badge>
              )}
            </div>
            <p className="text-muted-foreground">
              View and manage customer conversations
            </p>
          </div>
        </div>
        <Select value={botId.toString()} onValueChange={handleBotSelect}>
          <SelectTrigger className="w-[180px]">
            <SelectValue placeholder="Select bot" />
          </SelectTrigger>
          <SelectContent>
            {bots.map((bot) => (
              <SelectItem key={bot.id} value={bot.id.toString()}>
                {bot.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      {/* Status Tabs and Stats */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <Tabs value={statusFilter} onValueChange={handleStatusFilter}>
          <TabsList>
            <TabsTrigger value="all" className="gap-2">
              All
              {statusCounts && (
                <Badge variant="secondary" className="ml-1 text-xs">
                  {statusCounts.total}
                </Badge>
              )}
            </TabsTrigger>
            <TabsTrigger value="active" className="gap-2">
              Active
              {statusCounts && (
                <Badge variant="secondary" className="ml-1 text-xs">
                  {statusCounts.active}
                </Badge>
              )}
            </TabsTrigger>
            <TabsTrigger value="handover" className="gap-2">
              Handover
              {statusCounts && (
                <Badge variant="secondary" className="ml-1 text-xs">
                  {statusCounts.handover}
                </Badge>
              )}
            </TabsTrigger>
            <TabsTrigger value="closed" className="gap-2">
              Closed
              {statusCounts && (
                <Badge variant="secondary" className="ml-1 text-xs">
                  {statusCounts.closed}
                </Badge>
              )}
            </TabsTrigger>
          </TabsList>
        </Tabs>

        {/* Search and Sort */}
        <div className="flex items-center gap-2">
          <div className="relative">
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search conversations..."
              value={search}
              onChange={(e) => handleSearch(e.target.value)}
              className="pl-9 w-[200px]"
            />
          </div>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="icon">
                <Filter className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={() => handleSort('last_message_at')}>
                {sortBy === 'last_message_at' && (sortDirection === 'desc' ? <SortDesc className="h-4 w-4 mr-2" /> : <SortAsc className="h-4 w-4 mr-2" />)}
                Last Message
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => handleSort('created_at')}>
                {sortBy === 'created_at' && (sortDirection === 'desc' ? <SortDesc className="h-4 w-4 mr-2" /> : <SortAsc className="h-4 w-4 mr-2" />)}
                Created Date
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => handleSort('message_count')}>
                {sortBy === 'message_count' && (sortDirection === 'desc' ? <SortDesc className="h-4 w-4 mr-2" /> : <SortAsc className="h-4 w-4 mr-2" />)}
                Message Count
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* Loading state */}
      {isLoading && (
        <div className="flex items-center justify-center h-64">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      )}

      {/* Error state */}
      {error && (
        <div className="flex items-center justify-center h-64">
          <p className="text-destructive">Error loading conversations</p>
        </div>
      )}

      {/* Empty state */}
      {!isLoading && !error && conversations.length === 0 && (
        <Card>
          <CardHeader className="text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
              <MessageCircle className="h-6 w-6 text-muted-foreground" />
            </div>
            <CardTitle>No conversations yet</CardTitle>
            <CardDescription>
              {statusFilter !== 'all'
                ? `No ${statusFilter} conversations found`
                : 'Conversations will appear here once customers start chatting with your bot'}
            </CardDescription>
          </CardHeader>
        </Card>
      )}

      {/* Conversation list */}
      {!isLoading && !error && conversations.length > 0 && (
        <>
          <div className="space-y-3">
            {conversations.map((conversation) => (
              <ConversationCard
                key={conversation.id}
                conversation={conversation}
                botId={botId}
                onClose={() => handleClose(conversation)}
                onReopen={() => handleReopen(conversation)}
                onToggleHandover={() => handleToggleHandover(conversation)}
                isClosing={closeConversation.isPending}
                isReopening={reopenConversation.isPending}
                isTogglingHandover={toggleHandover.isPending}
              />
            ))}
          </div>

          {/* Pagination */}
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted-foreground">
                Showing {((meta.current_page - 1) * meta.per_page) + 1} to {Math.min(meta.current_page * meta.per_page, meta.total)} of {meta.total} conversations
              </p>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => handlePageChange(page - 1)}
                  disabled={page <= 1}
                >
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                <span className="text-sm">
                  Page {meta.current_page} of {meta.last_page}
                </span>
                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => handlePageChange(page + 1)}
                  disabled={page >= meta.last_page}
                >
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}

interface ConversationCardProps {
  conversation: Conversation;
  botId: number;
  onClose: () => void;
  onReopen: () => void;
  onToggleHandover: () => void;
  isClosing: boolean;
  isReopening: boolean;
  isTogglingHandover: boolean;
}

function ConversationCard({
  conversation,
  botId,
  onClose,
  onReopen,
  onToggleHandover,
  isClosing,
  isReopening,
  isTogglingHandover,
}: ConversationCardProps) {
  const customerName = conversation.customer_profile?.display_name || 'Unknown Customer';
  const customerInitial = customerName.charAt(0).toUpperCase();
  const lastMessageTime = conversation.last_message_at
    ? formatDistanceToNow(new Date(conversation.last_message_at), { addSuffix: true, locale: th })
    : null;

  return (
    <Card className="hover:bg-accent/50 transition-colors">
      <CardContent className="p-4">
        <div className="flex items-center gap-4">
          {/* Avatar */}
          <Avatar className="h-12 w-12">
            <AvatarImage src={conversation.customer_profile?.picture_url || undefined} />
            <AvatarFallback>{customerInitial}</AvatarFallback>
          </Avatar>

          {/* Content */}
          <Link
            to={`/conversations/${conversation.id}?botId=${botId}`}
            className="flex-1 min-w-0"
          >
            <div className="flex items-center gap-2 mb-1">
              <span className="font-medium truncate">{customerName}</span>
              <span className="text-lg" title={conversation.channel_type}>
                {channelIcons[conversation.channel_type] || '💬'}
              </span>
              <Badge
                variant="secondary"
                className={`${statusColors[conversation.status]} text-white text-xs`}
              >
                {conversation.status}
              </Badge>
              {conversation.is_handover && (
                <Badge variant="outline" className="text-xs">
                  <UserCheck className="h-3 w-3 mr-1" />
                  Handover
                </Badge>
              )}
            </div>
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <span>{conversation.message_count} messages</span>
              {lastMessageTime && (
                <>
                  <span>•</span>
                  <span>{lastMessageTime}</span>
                </>
              )}
              {conversation.tags && conversation.tags.length > 0 && (
                <>
                  <span>•</span>
                  {conversation.tags.slice(0, 3).map((tag) => (
                    <Badge key={tag} variant="outline" className="text-xs">
                      {tag}
                    </Badge>
                  ))}
                </>
              )}
            </div>
          </Link>

          {/* Actions */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon">
                <MoreVertical className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem asChild>
                <Link to={`/conversations/${conversation.id}?botId=${botId}`}>
                  <MessageSquare className="h-4 w-4 mr-2" />
                  View Conversation
                </Link>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onClick={onToggleHandover}
                disabled={isTogglingHandover}
              >
                <UserCheck className="h-4 w-4 mr-2" />
                {conversation.is_handover ? 'Enable Bot' : 'Take Over'}
              </DropdownMenuItem>
              {conversation.status !== 'closed' ? (
                <DropdownMenuItem
                  onClick={onClose}
                  disabled={isClosing}
                >
                  <X className="h-4 w-4 mr-2" />
                  Close Conversation
                </DropdownMenuItem>
              ) : (
                <DropdownMenuItem
                  onClick={onReopen}
                  disabled={isReopening}
                >
                  <RotateCcw className="h-4 w-4 mr-2" />
                  Reopen Conversation
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </CardContent>
    </Card>
  );
}
