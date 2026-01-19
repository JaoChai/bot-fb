---
id: react-005-state-refactor
title: State Management Refactoring
impact: HIGH
impactDescription: "Improve complex state handling with reducers or state machines"
category: react
tags: [state, reducer, zustand, complexity]
relatedRules: [react-004-context-pattern, react-002-extract-hook]
---

## Code Smell

- Multiple related useState calls
- Complex conditional logic for state updates
- Race conditions in state
- State combinations that shouldn't exist
- Hard to track state transitions

## Root Cause

1. State grew incrementally
2. Multiple related pieces added separately
3. No state design upfront
4. Async operations added complexity
5. Edge cases patched over time

## When to Apply

**Apply when:**
- > 3 related useState calls
- State transitions are complex
- Invalid state combinations possible
- Need to track state history
- Async flows with multiple states

**Don't apply when:**
- Simple independent states
- Would add unnecessary complexity
- State logic is clear

## Solution

### Before (Multiple useState)

```tsx
function ChatWindow({ conversationId }: { conversationId: string }) {
  const [messages, setMessages] = useState<Message[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const [isSending, setIsSending] = useState(false);
  const [sendError, setSendError] = useState<Error | null>(null);
  const [isTyping, setIsTyping] = useState(false);
  const [retryCount, setRetryCount] = useState(0);

  const loadMessages = async () => {
    setIsLoading(true);
    setError(null);
    try {
      const data = await api.messages.list(conversationId);
      setMessages(data);
    } catch (e) {
      setError(e as Error);
    } finally {
      setIsLoading(false);
    }
  };

  const sendMessage = async (content: string) => {
    setIsSending(true);
    setSendError(null);
    // Optimistic update
    const tempMessage = { id: 'temp', content, status: 'sending' };
    setMessages(prev => [...prev, tempMessage]);

    try {
      const message = await api.messages.send(conversationId, content);
      setMessages(prev => prev.map(m =>
        m.id === 'temp' ? message : m
      ));
      setRetryCount(0);
    } catch (e) {
      setSendError(e as Error);
      setMessages(prev => prev.map(m =>
        m.id === 'temp' ? { ...m, status: 'failed' } : m
      ));
      setRetryCount(prev => prev + 1);
    } finally {
      setIsSending(false);
    }
  };

  // Many more complex interactions...
}
```

### After (Reducer Pattern)

```tsx
// types/chat.ts
type ChatState = {
  messages: Message[];
  status: 'idle' | 'loading' | 'error' | 'success';
  error: Error | null;
  sendingIds: Set<string>;
  failedIds: Set<string>;
};

type ChatAction =
  | { type: 'LOAD_START' }
  | { type: 'LOAD_SUCCESS'; messages: Message[] }
  | { type: 'LOAD_ERROR'; error: Error }
  | { type: 'SEND_START'; tempId: string; content: string }
  | { type: 'SEND_SUCCESS'; tempId: string; message: Message }
  | { type: 'SEND_ERROR'; tempId: string; error: Error }
  | { type: 'RETRY'; tempId: string }
  | { type: 'RECEIVE_MESSAGE'; message: Message };

function chatReducer(state: ChatState, action: ChatAction): ChatState {
  switch (action.type) {
    case 'LOAD_START':
      return { ...state, status: 'loading', error: null };

    case 'LOAD_SUCCESS':
      return {
        ...state,
        status: 'success',
        messages: action.messages,
      };

    case 'LOAD_ERROR':
      return { ...state, status: 'error', error: action.error };

    case 'SEND_START':
      return {
        ...state,
        messages: [
          ...state.messages,
          {
            id: action.tempId,
            content: action.content,
            status: 'sending',
            created_at: new Date().toISOString(),
          } as Message,
        ],
        sendingIds: new Set([...state.sendingIds, action.tempId]),
      };

    case 'SEND_SUCCESS':
      return {
        ...state,
        messages: state.messages.map((m) =>
          m.id === action.tempId ? action.message : m
        ),
        sendingIds: new Set(
          [...state.sendingIds].filter((id) => id !== action.tempId)
        ),
      };

    case 'SEND_ERROR':
      return {
        ...state,
        messages: state.messages.map((m) =>
          m.id === action.tempId ? { ...m, status: 'failed' } : m
        ),
        sendingIds: new Set(
          [...state.sendingIds].filter((id) => id !== action.tempId)
        ),
        failedIds: new Set([...state.failedIds, action.tempId]),
      };

    case 'RECEIVE_MESSAGE':
      return {
        ...state,
        messages: [...state.messages, action.message],
      };

    default:
      return state;
  }
}

// hooks/useChat.ts
function useChat(conversationId: string) {
  const [state, dispatch] = useReducer(chatReducer, {
    messages: [],
    status: 'idle',
    error: null,
    sendingIds: new Set(),
    failedIds: new Set(),
  });

  const loadMessages = useCallback(async () => {
    dispatch({ type: 'LOAD_START' });
    try {
      const messages = await api.messages.list(conversationId);
      dispatch({ type: 'LOAD_SUCCESS', messages });
    } catch (error) {
      dispatch({ type: 'LOAD_ERROR', error: error as Error });
    }
  }, [conversationId]);

  const sendMessage = useCallback(async (content: string) => {
    const tempId = `temp-${Date.now()}`;
    dispatch({ type: 'SEND_START', tempId, content });

    try {
      const message = await api.messages.send(conversationId, content);
      dispatch({ type: 'SEND_SUCCESS', tempId, message });
    } catch (error) {
      dispatch({ type: 'SEND_ERROR', tempId, error: error as Error });
    }
  }, [conversationId]);

  // Real-time updates
  useEffect(() => {
    const unsubscribe = subscribeToMessages(conversationId, (message) => {
      dispatch({ type: 'RECEIVE_MESSAGE', message });
    });
    return unsubscribe;
  }, [conversationId]);

  return {
    ...state,
    loadMessages,
    sendMessage,
    isLoading: state.status === 'loading',
    isSending: state.sendingIds.size > 0,
  };
}

// ChatWindow.tsx - CLEAN
function ChatWindow({ conversationId }: { conversationId: string }) {
  const {
    messages,
    isLoading,
    isSending,
    error,
    loadMessages,
    sendMessage,
  } = useChat(conversationId);

  useEffect(() => {
    loadMessages();
  }, [loadMessages]);

  if (isLoading) return <ChatSkeleton />;
  if (error) return <ErrorMessage error={error} onRetry={loadMessages} />;

  return (
    <div className="flex flex-col h-full">
      <MessageList messages={messages} />
      <ChatInput onSend={sendMessage} disabled={isSending} />
    </div>
  );
}
```

### Step-by-Step

1. **Identify related states**
   - List all useState calls
   - Group related ones
   - Find invalid combinations

2. **Define state type**
   ```tsx
   type State = {
     // Combine related states
   };
   ```

3. **Define action types**
   ```tsx
   type Action =
     | { type: 'ACTION_1' }
     | { type: 'ACTION_2'; payload: Data };
   ```

4. **Create reducer**
   - Handle all state transitions
   - Return new state immutably

5. **Create hook**
   - Use useReducer
   - Expose actions as callbacks

## Verification

```bash
# Type check
npm run type-check

# Test all state transitions
npm run test -- useChat
```

## Anti-Patterns

- **Over-engineering**: Simple states don't need reducers
- **Huge reducer**: Split into multiple reducers if needed
- **Business logic in reducer**: Keep reducers pure
- **Missing types**: Always type actions

## Project-Specific Notes

**BotFacebook Context:**
- Complex state: Chat window, Bot builder
- Pattern: Hook wrapping reducer
- Location: `src/hooks/use{Feature}.ts`
- Consider Zustand for global complex state
