import { useState, useRef, useCallback } from 'react';
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

export function useStreamingChat({ botId, flowId }: UseStreamingChatOptions) {
  const [messages, setMessages] = useState<StreamingMessage[]>([]);
  const [isStreaming, setIsStreaming] = useState(false);
  const abortControllerRef = useRef<AbortController | null>(null);

  /**
   * Generate unique ID for messages
   */
  const generateId = useCallback(() => {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    return `msg-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
  }, []);

  /**
   * Send a message and stream the response
   */
  const sendMessage = useCallback(async (message: string) => {
    if (!botId || !flowId || !message.trim() || isStreaming) {
      return;
    }

    const userMsgId = generateId();
    const assistantMsgId = generateId();

    // Add user message
    setMessages(prev => [
      ...prev,
      { id: userMsgId, role: 'user', content: message.trim() }
    ]);

    // Build conversation history (exclude the current message)
    const history = messages.map(m => ({
      role: m.role,
      content: m.content,
    }));

    // Add placeholder for assistant with empty process logs
    setMessages(prev => [
      ...prev,
      {
        id: assistantMsgId,
        role: 'assistant',
        content: '',
        processLogs: [],
        isStreaming: true
      }
    ]);

    setIsStreaming(true);

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
            setMessages(prev => prev.map(m =>
              m.id === assistantMsgId
                ? { ...m, processLogs: [...(m.processLogs || []), log] }
                : m
            ));
          },
          onContent: (text) => {
            setMessages(prev => prev.map(m =>
              m.id === assistantMsgId
                ? { ...m, content: m.content + text }
                : m
            ));
          },
          onError: (error) => {
            setMessages(prev => prev.map(m =>
              m.id === assistantMsgId
                ? { ...m, content: `Error: ${error}`, isStreaming: false }
                : m
            ));
          },
          onDone: (summary) => {
            setMessages(prev => prev.map(m =>
              m.id === assistantMsgId
                ? { ...m, summary, isStreaming: false }
                : m
            ));
          },
          signal: abortControllerRef.current.signal,
        }
      );
    } catch (error) {
      const err = error as Error;
      if (err.name !== 'AbortError') {
        setMessages(prev => prev.map(m =>
          m.id === assistantMsgId
            ? { ...m, content: `Error: ${err.message}`, isStreaming: false }
            : m
        ));
      } else {
        // Aborted - mark as not streaming but keep content
        setMessages(prev => prev.map(m =>
          m.id === assistantMsgId
            ? { ...m, isStreaming: false }
            : m
        ));
      }
    } finally {
      setIsStreaming(false);
      abortControllerRef.current = null;
    }
  }, [botId, flowId, messages, isStreaming, generateId]);

  /**
   * Cancel the current stream
   */
  const cancelStream = useCallback(() => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      setIsStreaming(false);
    }
  }, []);

  /**
   * Clear all messages
   */
  const clearMessages = useCallback(() => {
    if (isStreaming) {
      cancelStream();
    }
    setMessages([]);
  }, [isStreaming, cancelStream]);

  return {
    messages,
    isStreaming,
    sendMessage,
    cancelStream,
    clearMessages,
  };
}

// Re-export ProcessLog type for components
export type { ProcessLog, DoneSummary };
