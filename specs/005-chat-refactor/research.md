# Research: Chat Page Refactor

**Created**: 2026-01-09 | **Feature**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md)

## Overview

This document captures research findings for the Chat Page refactor, covering best practices for component decomposition, service layer patterns, and state management optimization.

---

## 1. React Component Decomposition

### Decision: Container/Presentational Pattern with Composition

**Rationale**:
- Container components handle data fetching and state management
- Presentational components are pure UI, receive props, easy to test
- Composition allows flexible layout without prop drilling

**Alternatives Considered**:
| Pattern | Pros | Cons | Why Rejected |
|---------|------|------|--------------|
| Higher-Order Components | Reusable logic | Hard to type, nesting hell | TypeScript friction |
| Render Props | Flexible | Verbose, callback hell | Composition is cleaner |
| Container/Presentational | Clear separation | More files | ✅ Chosen for testability |

**Best Practices for This Project**:
1. **Max component size**: 200 lines (hard limit from spec SC-001)
2. **Single responsibility**: Each component does one thing
3. **Props interface**: Always define TypeScript interface for props
4. **Naming convention**: `[Domain][Purpose].tsx` (e.g., `MessageBubble.tsx`, `ConversationItem.tsx`)

**Reference Implementation**:
```tsx
// Container (ChatWindow.tsx)
export function ChatWindow({ conversationId }: Props) {
  const { messages } = useMessages(conversationId);
  const { sendMessage } = useSendMessage();

  return (
    <div className="flex flex-col h-full">
      <ChatHeader conversationId={conversationId} />
      <MessageList messages={messages} />
      <MessageInput onSend={sendMessage} />
    </div>
  );
}

// Presentational (MessageBubble.tsx)
interface MessageBubbleProps {
  message: Message;
  isOwn: boolean;
}
export function MessageBubble({ message, isOwn }: MessageBubbleProps) {
  return <div className={isOwn ? 'bg-blue-500' : 'bg-gray-200'}>...</div>;
}
```

---

## 2. Laravel Service Layer Pattern

### Decision: Domain-Specific Services with Dependency Injection

**Rationale**:
- Controllers become thin (routing + request validation + response formatting)
- Business logic is testable in isolation
- Services can be reused across controllers, jobs, commands

**Alternatives Considered**:
| Pattern | Pros | Cons | Why Rejected |
|---------|------|------|--------------|
| Fat Controllers | Simple, fast | Untestable, 1000+ lines | Current problem |
| Repository Pattern | DB abstraction | Overkill for Eloquent | Not needed |
| Action Classes | Single purpose | Too granular | Services better for grouping |
| Domain Services | Grouped logic | Need careful scoping | ✅ Chosen |

**Best Practices for This Project**:
1. **Service location**: `app/Services/Chat/`
2. **Naming**: `[Entity]Service.php` (e.g., `ConversationService.php`)
3. **Constructor injection**: Use Laravel's container for dependencies
4. **Return types**: Always specify return types
5. **Max service size**: 200 lines (aligned with controller limit)

**Reference Implementation**:
```php
// app/Services/Chat/MessageService.php
class MessageService
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly MessageRepository $messageRepository // if needed
    ) {}

    public function send(Conversation $conversation, string $content, User $sender): Message
    {
        // Business logic here
        $message = $conversation->messages()->create([
            'content' => $content,
            'sender_type' => get_class($sender),
            'sender_id' => $sender->id,
        ]);

        event(new MessageSent($message));
        return $message;
    }

    public function getForConversation(Conversation $conversation, int $limit = 50): Collection
    {
        return $conversation->messages()
            ->with(['sender'])
            ->orderByDesc('created_at')
            ->take($limit)
            ->get()
            ->reverse();
    }
}

// Controller becomes thin
class ConversationController extends Controller
{
    public function __construct(
        private readonly MessageService $messageService,
        private readonly ConversationService $conversationService
    ) {}

    public function sendMessage(SendMessageRequest $request, Conversation $conversation)
    {
        $message = $this->messageService->send(
            $conversation,
            $request->validated('content'),
            $request->user()
        );

        return new MessageResource($message);
    }
}
```

---

## 3. TanStack Query Hook Organization

### Decision: Domain-Specific Hooks in Dedicated Files

**Rationale**:
- Each domain (messages, conversations, notes) gets its own hook file
- Query keys are colocated with queries for maintainability
- Mutations are grouped with related queries

**Alternatives Considered**:
| Pattern | Pros | Cons | Why Rejected |
|---------|------|------|--------------|
| Single file | All in one place | 885 lines (current) | Too large |
| Per-query files | Very granular | Too many files | Maintenance overhead |
| Domain files | Balanced grouping | Need clear domains | ✅ Chosen |

**Best Practices for This Project**:
1. **File location**: `hooks/chat/` directory
2. **Query key factory**: Export from each file for cache invalidation
3. **Prefetching**: Use for known navigation patterns
4. **Optimistic updates**: Consistent pattern across mutations

**Reference Implementation**:
```tsx
// hooks/chat/useMessages.ts
export const messageKeys = {
  all: ['messages'] as const,
  list: (conversationId: string) => [...messageKeys.all, 'list', conversationId] as const,
  detail: (id: string) => [...messageKeys.all, 'detail', id] as const,
};

export function useMessages(conversationId: string) {
  return useQuery({
    queryKey: messageKeys.list(conversationId),
    queryFn: () => api.messages.list(conversationId),
    staleTime: 30_000,
  });
}

export function useSendMessage() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: api.messages.send,
    onMutate: async (newMessage) => {
      // Optimistic update
      await queryClient.cancelQueries({ queryKey: messageKeys.list(newMessage.conversationId) });
      const previous = queryClient.getQueryData(messageKeys.list(newMessage.conversationId));
      queryClient.setQueryData(messageKeys.list(newMessage.conversationId), (old) => [...old, newMessage]);
      return { previous };
    },
    onError: (err, newMessage, context) => {
      queryClient.setQueryData(messageKeys.list(newMessage.conversationId), context?.previous);
    },
    onSettled: (data, error, variables) => {
      queryClient.invalidateQueries({ queryKey: messageKeys.list(variables.conversationId) });
    },
  });
}
```

---

## 4. Channel Adapter Pattern

### Decision: Strategy Pattern with React Context

**Rationale**:
- Each channel (LINE, Telegram, Facebook) has different message formats
- Adapter pattern allows adding new channels without modifying existing code
- React Context provides adapter to components without prop drilling

**Alternatives Considered**:
| Pattern | Pros | Cons | Why Rejected |
|---------|------|------|--------------|
| Switch statements | Simple | Every component needs switch | Violates DRY |
| Higher-Order Component | Wraps components | Type complexity | Composition preferred |
| Strategy + Context | Clean, extensible | Setup overhead | ✅ Chosen |

**Reference Implementation**:
```tsx
// adapters/ChannelAdapter.ts
export interface ChannelAdapter {
  name: 'line' | 'telegram' | 'facebook';
  renderMessage: (message: Message) => ReactNode;
  renderAvatar: (customer: Customer) => ReactNode;
  getMediaUploadConfig: () => MediaUploadConfig;
  formatOutgoingMessage: (content: string) => OutgoingPayload;
}

// adapters/LineAdapter.tsx
export const lineAdapter: ChannelAdapter = {
  name: 'line',
  renderMessage: (message) => <LineMessage message={message} />,
  renderAvatar: (customer) => <LineAvatar customer={customer} />,
  getMediaUploadConfig: () => ({ maxSize: 10 * 1024 * 1024, formats: ['jpg', 'png', 'mp4'] }),
  formatOutgoingMessage: (content) => ({ type: 'text', text: content }),
};

// Context provider
const ChannelContext = createContext<ChannelAdapter>(lineAdapter);

export function ChannelProvider({ channel, children }: Props) {
  const adapter = useMemo(() => {
    switch (channel) {
      case 'line': return lineAdapter;
      case 'telegram': return telegramAdapter;
      case 'facebook': return facebookAdapter;
    }
  }, [channel]);

  return <ChannelContext.Provider value={adapter}>{children}</ChannelContext.Provider>;
}

export function useChannel() {
  return useContext(ChannelContext);
}
```

---

## 5. Zustand Store for UI State

### Decision: Single Store with Slices for Chat UI State

**Rationale**:
- Local UI state (selected conversation, panel visibility) doesn't need React Query
- Zustand is simpler than Redux, already in project
- Persist selected conversation for page refresh

**Best Practices**:
1. **Separate from server state**: Only UI concerns
2. **Slices**: Group related state
3. **Actions**: Colocate with state they modify

**Reference Implementation**:
```tsx
// stores/chatStore.ts
interface ChatState {
  selectedConversationId: string | null;
  isCustomerPanelOpen: boolean;
  searchQuery: string;

  // Actions
  selectConversation: (id: string | null) => void;
  toggleCustomerPanel: () => void;
  setSearchQuery: (query: string) => void;
}

export const useChatStore = create<ChatState>()(
  persist(
    (set) => ({
      selectedConversationId: null,
      isCustomerPanelOpen: true,
      searchQuery: '',

      selectConversation: (id) => set({ selectedConversationId: id }),
      toggleCustomerPanel: () => set((s) => ({ isCustomerPanelOpen: !s.isCustomerPanelOpen })),
      setSearchQuery: (query) => set({ searchQuery: query }),
    }),
    { name: 'chat-store', partialize: (s) => ({ selectedConversationId: s.selectedConversationId }) }
  )
);
```

---

## 6. WebSocket Real-time Updates

### Decision: Dedicated useRealtime Hook with Echo

**Rationale**:
- Current implementation has WebSocket logic scattered in components
- Dedicated hook centralizes subscription management
- Easy to test and maintain

**Reference Implementation**:
```tsx
// hooks/chat/useRealtime.ts
export function useRealtime(conversationId: string | null) {
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!conversationId) return;

    const channel = Echo.private(`conversation.${conversationId}`)
      .listen('MessageReceived', (e: MessageEvent) => {
        queryClient.setQueryData(messageKeys.list(conversationId), (old: Message[]) =>
          [...old, e.message]
        );
      })
      .listen('MessageRead', (e: ReadEvent) => {
        queryClient.invalidateQueries({ queryKey: messageKeys.list(conversationId) });
      });

    return () => channel.stopListening();
  }, [conversationId, queryClient]);
}
```

---

## Summary of Decisions

| Area | Decision | Key Benefit |
|------|----------|-------------|
| Components | Container/Presentational | Testability, reusability |
| Backend | Domain Services | Thin controllers, testable logic |
| Hooks | Domain-specific files | Maintainability, clear ownership |
| Channels | Adapter pattern + Context | Extensibility, DRY |
| UI State | Zustand single store | Simple, separate from server state |
| Real-time | Dedicated hook | Centralized, testable |

All decisions align with spec requirements (FR-001 through FR-016) and success criteria (SC-001 through SC-008).
