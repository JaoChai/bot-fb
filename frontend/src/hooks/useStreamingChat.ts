import { useReducer, useRef, useCallback } from 'react';
import { streamFlowTest, createStreamAbortController, type ProcessLog, type DoneSummary } from '@/lib/stream';

export interface StreamingMessage {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  processLogs?: ProcessLog[];
  summary?: DoneSummary;
  isStreaming?: boolean;
}

interface UseStreamingChatOptions {
  botId: number | null;
  flowId: number | null;
}

// State shape
interface ChatState {
  messages: StreamingMessage[];
  isStreaming: boolean;
}

// Action types for reducer
type ChatAction =
  | { type: 'ADD_USER_MESSAGE'; payload: { id: string; content: string } }
  | { type: 'ADD_ASSISTANT_PLACEHOLDER'; payload: { id: string } }
  | { type: 'APPEND_PROCESS_LOG'; payload: { messageId: string; log: ProcessLog } }
  | { type: 'APPEND_CONTENT'; payload: { messageId: string; text: string } }
  | { type: 'SET_ERROR'; payload: { messageId: string; error: string } }
  | { type: 'SET_DONE'; payload: { messageId: string; summary?: DoneSummary } }
  | { type: 'SET_ABORTED'; payload: { messageId: string } }
  | { type: 'SET_STREAMING'; payload: boolean }
  | { type: 'CLEAR_MESSAGES' };

// Initial state
const initialState: ChatState = {
  messages: [],
  isStreaming: false,
};

// Reducer function - batches all state updates
function chatReducer(state: ChatState, action: ChatAction): ChatState {
  switch (action.type) {
    case 'ADD_USER_MESSAGE':
      return {
        ...state,
        messages: [
          ...state.messages,
          { id: action.payload.id, role: 'user', content: action.payload.content }
        ],
      };

    case 'ADD_ASSISTANT_PLACEHOLDER':
      return {
        ...state,
        messages: [
          ...state.messages,
          {
            id: action.payload.id,
            role: 'assistant',
            content: '',
            processLogs: [],
            isStreaming: true,
          }
        ],
        isStreaming: true,
      };

    case 'APPEND_PROCESS_LOG':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, processLogs: [...(m.processLogs || []), action.payload.log] }
            : m
        ),
      };

    case 'APPEND_CONTENT':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, content: m.content + action.payload.text }
            : m
        ),
      };

    case 'SET_ERROR':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, content: `Error: ${action.payload.error}`, isStreaming: false }
            : m
        ),
        isStreaming: false,
      };

    case 'SET_DONE':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, summary: action.payload.summary, isStreaming: false }
            : m
        ),
        isStreaming: false,
      };

    case 'SET_ABORTED':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, isStreaming: false }
            : m
        ),
        isStreaming: false,
      };

    case 'SET_STREAMING':
      return {
        ...state,
        isStreaming: action.payload,
      };

    case 'CLEAR_MESSAGES':
      return {
        ...state,
        messages: [],
        isStreaming: false,
      };

    default:
      return state;
  }
}

/**
 * Generate unique ID for messages
 */
function generateId(): string {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
}

export function useStreamingChat({ botId, flowId }: UseStreamingChatOptions) {
  const [state, dispatch] = useReducer(chatReducer, initialState);
  const abortControllerRef = useRef<AbortController | null>(null);

  /**
   * Send a message and stream the response
   */
  const sendMessage = useCallback(async (message: string) => {
    if (!botId || !flowId || !message.trim() || state.isStreaming) {
      return;
    }

    const userMsgId = generateId();
    const assistantMsgId = generateId();

    // Add user message
    dispatch({ type: 'ADD_USER_MESSAGE', payload: { id: userMsgId, content: message.trim() } });

    // Build conversation history (exclude the current message)
    const history = state.messages.map(m => ({
      role: m.role,
      content: m.content,
    }));

    // Add placeholder for assistant (also sets isStreaming: true)
    dispatch({ type: 'ADD_ASSISTANT_PLACEHOLDER', payload: { id: assistantMsgId } });

    // Create abort controller
    abortControllerRef.current = createStreamAbortController();

    try {
      await streamFlowTest(
        botId,
        flowId,
        message.trim(),
        history,
        false, // enableThinking deprecated
        {
          onProcessLog: (log) => {
            dispatch({ type: 'APPEND_PROCESS_LOG', payload: { messageId: assistantMsgId, log } });
          },
          onContent: (text) => {
            dispatch({ type: 'APPEND_CONTENT', payload: { messageId: assistantMsgId, text } });
          },
          onError: (error) => {
            dispatch({ type: 'SET_ERROR', payload: { messageId: assistantMsgId, error } });
          },
          onDone: (summary) => {
            dispatch({ type: 'SET_DONE', payload: { messageId: assistantMsgId, summary } });
          },
          signal: abortControllerRef.current.signal,
        }
      );
    } catch (error) {
      const err = error as Error;
      if (err.name !== 'AbortError') {
        dispatch({ type: 'SET_ERROR', payload: { messageId: assistantMsgId, error: err.message } });
      } else {
        // Aborted - mark as not streaming but keep content
        dispatch({ type: 'SET_ABORTED', payload: { messageId: assistantMsgId } });
      }
    } finally {
      abortControllerRef.current = null;
    }
  }, [botId, flowId, state.messages, state.isStreaming]);

  /**
   * Cancel the current stream
   */
  const cancelStream = useCallback(() => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }
  }, []);

  /**
   * Clear all messages
   */
  const clearMessages = useCallback(() => {
    if (state.isStreaming) {
      cancelStream();
    }
    dispatch({ type: 'CLEAR_MESSAGES' });
  }, [state.isStreaming, cancelStream]);

  return {
    messages: state.messages,
    isStreaming: state.isStreaming,
    sendMessage,
    cancelStream,
    clearMessages,
  };
}

// Re-export ProcessLog type for components
export type { ProcessLog, DoneSummary };
