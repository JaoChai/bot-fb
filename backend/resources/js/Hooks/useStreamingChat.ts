import { useState, useRef, useCallback } from 'react';

/**
 * SSE streaming state
 */
export type StreamingState = 'idle' | 'connecting' | 'streaming' | 'completed' | 'error' | 'cancelled';

/**
 * SSE event types from the server
 */
export interface SSEEvent {
  /** Event type: 'token', 'done', 'error', etc. */
  event?: string;
  /** Event data payload */
  data: string;
}

/**
 * Options for useStreamingChat hook
 */
export interface UseStreamingChatOptions {
  /** API endpoint for SSE streaming (e.g., '/api/flows/{id}/test') */
  endpoint: string;
  /** Callback fired for each token received */
  onToken?: (token: string) => void;
  /** Callback fired when streaming completes with full response */
  onComplete?: (fullResponse: string) => void;
  /** Callback fired on error */
  onError?: (error: Error) => void;
  /** Callback fired when stream is cancelled */
  onCancel?: () => void;
  /** Custom headers to include in request */
  headers?: Record<string, string>;
}

/**
 * Return value from useStreamingChat hook
 */
export interface UseStreamingChatReturn {
  /** Send a message to start streaming */
  sendMessage: (message: string, context?: Record<string, unknown>) => Promise<void>;
  /** Cancel the current streaming request */
  cancel: () => void;
  /** Reset state to initial values */
  reset: () => void;
  /** Whether currently streaming */
  isStreaming: boolean;
  /** Current streaming state */
  state: StreamingState;
  /** Array of received tokens */
  tokens: string[];
  /** Full concatenated response */
  fullResponse: string;
  /** Error if any occurred */
  error: Error | null;
}

/**
 * Get CSRF token from meta tag (Laravel Inertia)
 */
const getCsrfToken = (): string => {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  return token || '';
};

/**
 * Parse SSE data line into event object
 * Handles 'data: {...}' format from server
 */
const parseSSELine = (line: string): SSEEvent | null => {
  const trimmed = line.trim();

  if (!trimmed || trimmed.startsWith(':')) {
    // Empty line or comment, skip
    return null;
  }

  if (trimmed.startsWith('data:')) {
    const data = trimmed.slice(5).trim();
    return { data };
  }

  if (trimmed.startsWith('event:')) {
    const event = trimmed.slice(6).trim();
    return { event, data: '' };
  }

  return null;
};

/**
 * Hook for SSE streaming chat with AbortController support
 *
 * Features:
 * - Token-by-token streaming via Server-Sent Events
 * - AbortController for request cancellation
 * - Automatic state management
 * - Error handling with graceful fallback
 * - Support for bot context (flow_id, settings, etc.)
 *
 * @example
 * ```tsx
 * const { sendMessage, cancel, isStreaming, fullResponse } = useStreamingChat({
 *   endpoint: `/api/flows/${flowId}/test`,
 *   onToken: (token) => console.log('Token:', token),
 *   onComplete: (response) => console.log('Done:', response),
 * });
 *
 * // Start streaming
 * sendMessage('Hello, test this flow');
 *
 * // Cancel mid-stream
 * cancel();
 * ```
 */
export function useStreamingChat({
  endpoint,
  onToken,
  onComplete,
  onError,
  onCancel,
  headers: customHeaders,
}: UseStreamingChatOptions): UseStreamingChatReturn {
  const [state, setState] = useState<StreamingState>('idle');
  const [tokens, setTokens] = useState<string[]>([]);
  const [fullResponse, setFullResponse] = useState<string>('');
  const [error, setError] = useState<Error | null>(null);

  // Ref to hold AbortController for cancellation
  const abortControllerRef = useRef<AbortController | null>(null);
  // Ref to track accumulated response (for callbacks)
  const accumulatedRef = useRef<string>('');

  /**
   * Reset all state to initial values
   */
  const reset = useCallback(() => {
    setState('idle');
    setTokens([]);
    setFullResponse('');
    setError(null);
    accumulatedRef.current = '';
  }, []);

  /**
   * Cancel the current streaming request
   */
  const cancel = useCallback(() => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
      setState('cancelled');
      onCancel?.();
    }
  }, [onCancel]);

  /**
   * Send a message and start streaming the response
   */
  const sendMessage = useCallback(async (
    message: string,
    context?: Record<string, unknown>
  ): Promise<void> => {
    // Cancel any existing request
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    // Reset state for new request
    reset();
    setState('connecting');

    // Create new AbortController
    const controller = new AbortController();
    abortControllerRef.current = controller;

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'text/event-stream',
          'X-CSRF-TOKEN': getCsrfToken(),
          'X-Requested-With': 'XMLHttpRequest',
          ...customHeaders,
        },
        credentials: 'same-origin',
        signal: controller.signal,
        body: JSON.stringify({
          message,
          ...context,
        }),
      });

      if (!response.ok) {
        const errorText = await response.text();
        let errorMessage: string;
        try {
          const errorJson = JSON.parse(errorText);
          errorMessage = errorJson.message || errorJson.error || `HTTP ${response.status}`;
        } catch {
          errorMessage = errorText || `HTTP ${response.status}: ${response.statusText}`;
        }
        throw new Error(errorMessage);
      }

      if (!response.body) {
        throw new Error('Response body is not available for streaming');
      }

      setState('streaming');

      const reader = response.body.getReader();
      const decoder = new TextDecoder('utf-8');
      let buffer = '';

      while (true) {
        const { done, value } = await reader.read();

        if (done) {
          // Stream completed
          setState('completed');
          onComplete?.(accumulatedRef.current);
          break;
        }

        // Decode chunk and add to buffer
        buffer += decoder.decode(value, { stream: true });

        // Process complete SSE messages (separated by double newlines)
        const lines = buffer.split('\n');

        // Keep the last potentially incomplete line in buffer
        buffer = lines.pop() || '';

        for (const line of lines) {
          const event = parseSSELine(line);

          if (!event || !event.data) continue;

          // Handle special events
          if (event.data === '[DONE]') {
            setState('completed');
            onComplete?.(accumulatedRef.current);
            return;
          }

          // Try to parse JSON data
          try {
            const parsed = JSON.parse(event.data);

            // Handle different response formats
            let token: string | null = null;

            if (typeof parsed === 'string') {
              token = parsed;
            } else if (parsed.token) {
              token = parsed.token;
            } else if (parsed.content) {
              token = parsed.content;
            } else if (parsed.text) {
              token = parsed.text;
            } else if (parsed.delta?.content) {
              // OpenAI-style delta format
              token = parsed.delta.content;
            } else if (parsed.choices?.[0]?.delta?.content) {
              // OpenAI API format
              token = parsed.choices[0].delta.content;
            }

            // Handle error messages from server
            if (parsed.error) {
              throw new Error(parsed.error);
            }

            // Handle done signal in JSON
            if (parsed.done === true || parsed.finished === true) {
              setState('completed');
              onComplete?.(accumulatedRef.current);
              return;
            }

            if (token !== null) {
              accumulatedRef.current += token;
              setTokens((prev) => [...prev, token!]);
              setFullResponse(accumulatedRef.current);
              onToken?.(token);
            }
          } catch (parseError) {
            // If not JSON, treat as raw token
            if (event.data && event.data !== '[DONE]') {
              const token = event.data;
              accumulatedRef.current += token;
              setTokens((prev) => [...prev, token]);
              setFullResponse(accumulatedRef.current);
              onToken?.(token);
            }
          }
        }
      }
    } catch (err) {
      // Handle abort
      if (err instanceof Error && err.name === 'AbortError') {
        setState('cancelled');
        onCancel?.();
        return;
      }

      // Handle other errors
      const error = err instanceof Error ? err : new Error(String(err));
      setState('error');
      setError(error);
      onError?.(error);
    } finally {
      abortControllerRef.current = null;
    }
  }, [endpoint, customHeaders, reset, onToken, onComplete, onError, onCancel]);

  return {
    sendMessage,
    cancel,
    reset,
    isStreaming: state === 'connecting' || state === 'streaming',
    state,
    tokens,
    fullResponse,
    error,
  };
}

export default useStreamingChat;
