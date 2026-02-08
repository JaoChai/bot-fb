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
  | { type: 'REPLACE_CONTENT'; payload: { messageId: string; content: string } }
  | { type: 'FLUSH_STREAMING_CONTENT'; payload: { messageId: string; content: string } }
  | { type: 'SET_ERROR'; payload: { messageId: string; error: string } }
  | { type: 'SET_DONE'; payload: { messageId: string; summary?: DoneSummary } }
  | { type: 'SET_ABORTED'; payload: { messageId: string } }
  | { type: 'SET_STREAMING'; payload: boolean }
  | { type: 'CLEAR_MESSAGES' };

// Maximum messages to keep in memory (F4: prevent memory leak)
const MAX_MESSAGES = 100;

// Initial state
const initialState: ChatState = {
  messages: [],
  isStreaming: false,
};

// Reducer function - batches all state updates
function chatReducer(state: ChatState, action: ChatAction): ChatState {
  switch (action.type) {
    case 'ADD_USER_MESSAGE': {
      const newMessages = [
        ...state.messages,
        { id: action.payload.id, role: 'user' as const, content: action.payload.content }
      ];
      return {
        ...state,
        messages: newMessages.length > MAX_MESSAGES ? newMessages.slice(-MAX_MESSAGES) : newMessages,
      };
    }

    case 'ADD_ASSISTANT_PLACEHOLDER': {
      const newMessages = [
        ...state.messages,
        {
          id: action.payload.id,
          role: 'assistant' as const,
          content: '',
          processLogs: [],
          isStreaming: true,
        }
      ];
      return {
        ...state,
        messages: newMessages.length > MAX_MESSAGES ? newMessages.slice(-MAX_MESSAGES) : newMessages,
        isStreaming: true,
      };
    }

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

    case 'REPLACE_CONTENT':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, content: action.payload.content }
            : m
        ),
      };

    case 'FLUSH_STREAMING_CONTENT':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, content: action.payload.content }
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

// Heartbeat timeout (30 seconds) - abort if no events received
const HEARTBEAT_TIMEOUT_MS = 30000;
// Heartbeat check interval (5 seconds)
const HEARTBEAT_CHECK_INTERVAL_MS = 5000;

export function useStreamingChat({ botId, flowId }: UseStreamingChatOptions) {
  const [state, dispatch] = useReducer(chatReducer, initialState);
  const abortControllerRef = useRef<AbortController | null>(null);
  const heartbeatIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const lastEventTimeRef = useRef<number>(0);
  const isCompletedRef = useRef<boolean>(false);

  // F1: Batched streaming content refs
  const streamingContentRef = useRef<Map<string, string>>(new Map());
  const flushIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);

  // F3: Refs for stable sendMessage callback
  const messagesRef = useRef(state.messages);
  messagesRef.current = state.messages;

  const isStreamingRef = useRef(state.isStreaming);
  isStreamingRef.current = state.isStreaming;

  // F1: Flush accumulated streaming content to state
  const flushStreamingContent = useCallback(() => {
    streamingContentRef.current.forEach((content, messageId) => {
      dispatch({ type: 'FLUSH_STREAMING_CONTENT', payload: { messageId, content } });
    });
  }, []);

  // F1: Start flush interval (50ms)
  const startFlushInterval = useCallback(() => {
    if (flushIntervalRef.current) return;
    flushIntervalRef.current = setInterval(() => {
      flushStreamingContent();
    }, 50);
  }, [flushStreamingContent]);

  // F1: Stop flush interval and do final flush
  const stopFlushInterval = useCallback(() => {
    if (flushIntervalRef.current) {
      clearInterval(flushIntervalRef.current);
      flushIntervalRef.current = null;
    }
    // Final flush of any remaining content
    flushStreamingContent();
    streamingContentRef.current.clear();
  }, [flushStreamingContent]);

  /**
   * Send a message and stream the response
   */
  const sendMessage = useCallback(async (message: string) => {
    if (!botId || !flowId || !message.trim() || isStreamingRef.current) {
      return;
    }

    const userMsgId = generateId();
    const assistantMsgId = generateId();

    // Add user message
    dispatch({ type: 'ADD_USER_MESSAGE', payload: { id: userMsgId, content: message.trim() } });

    // Build conversation history (exclude the current message)
    const history = messagesRef.current.map(m => ({
      role: m.role,
      content: m.content,
    }));

    // Add placeholder for assistant (also sets isStreaming: true)
    dispatch({ type: 'ADD_ASSISTANT_PLACEHOLDER', payload: { id: assistantMsgId } });

    // Create abort controller
    abortControllerRef.current = createStreamAbortController();

    // Reset completion tracking
    isCompletedRef.current = false;
    lastEventTimeRef.current = Date.now();

    // F1: Initialize streaming content buffer
    streamingContentRef.current.set(assistantMsgId, '');

    // Helper to reset heartbeat on any event
    const resetHeartbeat = () => {
      lastEventTimeRef.current = Date.now();
    };

    // Set up heartbeat check - abort if no events received for HEARTBEAT_TIMEOUT_MS
    heartbeatIntervalRef.current = setInterval(() => {
      const timeSinceLastEvent = Date.now() - lastEventTimeRef.current;
      if (timeSinceLastEvent > HEARTBEAT_TIMEOUT_MS) {
        console.warn(`No events received for ${HEARTBEAT_TIMEOUT_MS}ms, aborting stream`);
        // Only abort if not already completed
        if (!isCompletedRef.current) {
          isCompletedRef.current = true;
          stopFlushInterval();
          dispatch({ type: 'SET_ABORTED', payload: { messageId: assistantMsgId } });
          abortControllerRef.current?.abort();
        }
        // Clear the interval
        if (heartbeatIntervalRef.current) {
          clearInterval(heartbeatIntervalRef.current);
          heartbeatIntervalRef.current = null;
        }
      }
    }, HEARTBEAT_CHECK_INTERVAL_MS);

    // F1: Start flush interval before streaming
    startFlushInterval();

    try {
      await streamFlowTest(
        botId,
        flowId,
        message.trim(),
        history,
        false, // enableThinking deprecated
        {
          onProcessLog: (log) => {
            resetHeartbeat();
            dispatch({ type: 'APPEND_PROCESS_LOG', payload: { messageId: assistantMsgId, log } });
          },
          onContent: (text) => {
            resetHeartbeat();
            // F1: Accumulate in ref instead of dispatching per-chunk
            const current = streamingContentRef.current.get(assistantMsgId) ?? '';
            streamingContentRef.current.set(assistantMsgId, current + text);
          },
          onContentReplace: (content) => {
            resetHeartbeat();
            // F1: Update ref too so flush doesn't overwrite with stale data
            streamingContentRef.current.set(assistantMsgId, content);
            dispatch({ type: 'REPLACE_CONTENT', payload: { messageId: assistantMsgId, content } });
          },
          onError: (error) => {
            resetHeartbeat();
            // Prevent double completion
            if (isCompletedRef.current) return;
            stopFlushInterval();
            dispatch({ type: 'SET_ERROR', payload: { messageId: assistantMsgId, error } });
          },
          onDone: (summary) => {
            resetHeartbeat();
            // Prevent double completion (race condition fix)
            if (isCompletedRef.current) return;
            isCompletedRef.current = true;

            // Clear heartbeat interval on successful completion
            if (heartbeatIntervalRef.current) {
              clearInterval(heartbeatIntervalRef.current);
              heartbeatIntervalRef.current = null;
            }
            // F1: Final flush before marking done
            stopFlushInterval();
            dispatch({ type: 'SET_DONE', payload: { messageId: assistantMsgId, summary } });
          },
          signal: abortControllerRef.current.signal,
        }
      );
    } catch (error) {
      const err = error as Error;
      // Prevent double completion
      if (!isCompletedRef.current) {
        isCompletedRef.current = true;
        // F1: Final flush before error/abort
        stopFlushInterval();
        if (err.name !== 'AbortError') {
          dispatch({ type: 'SET_ERROR', payload: { messageId: assistantMsgId, error: err.message } });
        } else {
          // Aborted - mark as not streaming but keep content
          dispatch({ type: 'SET_ABORTED', payload: { messageId: assistantMsgId } });
        }
      }
    } finally {
      // Clean up heartbeat interval
      if (heartbeatIntervalRef.current) {
        clearInterval(heartbeatIntervalRef.current);
        heartbeatIntervalRef.current = null;
      }
      // F1: Ensure flush interval is cleaned up
      stopFlushInterval();
      abortControllerRef.current = null;
    }
  }, [botId, flowId, startFlushInterval, stopFlushInterval]);

  /**
   * Cancel the current stream
   */
  const cancelStream = useCallback(() => {
    // Clear heartbeat interval
    if (heartbeatIntervalRef.current) {
      clearInterval(heartbeatIntervalRef.current);
      heartbeatIntervalRef.current = null;
    }
    // Abort the stream
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }
  }, []);

  /**
   * Clear all messages
   */
  const clearMessages = useCallback(() => {
    if (isStreamingRef.current) {
      cancelStream();
    }
    streamingContentRef.current.clear();
    dispatch({ type: 'CLEAR_MESSAGES' });
  }, [cancelStream]);

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
