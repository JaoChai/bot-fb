/**
 * Streaming API helper using Server-Sent Events (SSE)
 * Handles real-time AI responses with System Process Logging
 */

// Process Log Event Types
export type ProcessEventType =
  | 'process_start'
  | 'decision_start'
  | 'decision_result'
  | 'decision_fallback'
  | 'decision_skip'
  | 'kb_search'
  | 'kb_result'
  | 'kb_skip'
  | 'chat_start'
  | 'chat_fallback'
  // Agentic Mode events
  | 'agent_start'
  | 'agent_thinking'
  | 'agent_done'
  | 'agent_error'
  | 'agent_fallback'
  | 'agent_max_iterations'
  | 'tool_call'
  | 'tool_result'
  // Safety events
  | 'agent_safety_stop'
  // Second AI events
  | 'second_ai_start'
  | 'second_ai_result'
  | 'second_ai_modified'
  // Standard events
  | 'content'
  | 'error'
  | 'done';

export interface ProcessLog {
  id: string;
  event: ProcessEventType;
  timestamp: number;
  data: Record<string, unknown>;
}

export interface StreamOptions {
  onProcessLog?: (log: ProcessLog) => void;
  onContent?: (text: string) => void;
  onContentReplace?: (text: string) => void;
  onError?: (message: string) => void;
  onDone?: (summary: DoneSummary) => void;
  signal?: AbortSignal;
}

export interface DoneSummary {
  total_time_ms: number;
  prompt_tokens: number;
  completion_tokens: number;
  models_used: string[];
  tool_calls?: number;
}

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

/**
 * Generate unique ID for process logs
 */
function generateLogId(): string {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return `log-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * Stream AI response with System Process Logging.
 * Uses native fetch API for SSE streaming.
 */
export async function streamFlowTest(
  botId: number,
  flowId: number,
  message: string,
  conversationHistory: Array<{ role: 'user' | 'assistant'; content: string }>,
  _enableThinking: boolean, // Deprecated - kept for backward compatibility
  options: StreamOptions,
  conversationId?: number
): Promise<void> {
  const token = localStorage.getItem('auth_token');

  if (!token) {
    options.onError?.('Not authenticated');
    return;
  }

  const response = await fetch(
    `${API_BASE_URL}/bots/${botId}/flows/${flowId}/stream`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'text/event-stream',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({
        message,
        conversation_history: conversationHistory,
        ...(conversationId && { conversation_id: conversationId }),
      }),
      signal: options.signal,
    }
  );

  if (!response.ok) {
    // Handle 401 Unauthorized - redirect to login
    if (response.status === 401) {
      localStorage.removeItem('auth_token');
      window.location.href = '/login';
      throw new Error('Session expired. Please login again.');
    }

    const errorText = await response.text();
    throw new Error(`HTTP ${response.status}: ${errorText || response.statusText}`);
  }

  const reader = response.body?.getReader();
  if (!reader) {
    throw new Error('Response body is not readable');
  }

  const decoder = new TextDecoder();
  let buffer = '';
  let receivedDoneEvent = false;
  const streamStartTime = Date.now();

  try {
    while (true) {
      const { done, value } = await reader.read();

      if (done) {
        if (buffer.trim()) {
          processSSEBuffer(buffer, options, (event) => {
            if (event === 'done') receivedDoneEvent = true;
          });
        }

        // If stream ended without done event, trigger done callback with fallback values
        if (!receivedDoneEvent) {
          console.warn('Stream ended without done event, triggering fallback');
          options.onDone?.({
            total_time_ms: Date.now() - streamStartTime,
            prompt_tokens: 0,
            completion_tokens: 0,
            models_used: [],
            tool_calls: 0,
          });
        }
        break;
      }

      buffer += decoder.decode(value, { stream: true });

      // Process complete events (each event ends with \n\n)
      const events = buffer.split('\n\n');
      buffer = events.pop() || '';

      for (const eventBlock of events) {
        if (!eventBlock.trim()) continue;
        processSSEEvent(eventBlock, options, (event) => {
          if (event === 'done') receivedDoneEvent = true;
        });
      }
    }
  } finally {
    reader.releaseLock();
  }
}

/**
 * Process a complete SSE event block
 */
function processSSEEvent(
  eventBlock: string,
  options: StreamOptions,
  onEventType?: (event: string) => void
): void {
  const lines = eventBlock.split('\n');
  let eventType: string | null = null;
  let data: string | null = null;

  for (const line of lines) {
    if (line.startsWith('event: ')) {
      eventType = line.slice(7).trim();
    } else if (line.startsWith('data: ')) {
      data = line.slice(6);
    }
  }

  if (!eventType || data === null) return;

  try {
    const parsed = JSON.parse(data);

    // Create process log for all events except content
    if (eventType !== 'content') {
      const log: ProcessLog = {
        id: generateLogId(),
        event: eventType as ProcessEventType,
        timestamp: Date.now(),
        data: parsed,
      };
      options.onProcessLog?.(log);
    }

    // Handle specific events
    switch (eventType) {
      case 'content':
        if (parsed.text) {
          options.onContent?.(parsed.text);
        }
        break;

      case 'error':
        options.onError?.(parsed.message || 'Unknown error');
        break;

      case 'done':
        onEventType?.('done');
        options.onDone?.({
          total_time_ms: parsed.total_time_ms || 0,
          prompt_tokens: parsed.prompt_tokens || 0,
          completion_tokens: parsed.completion_tokens || 0,
          models_used: parsed.models_used || [],
          tool_calls: parsed.tool_calls || 0,
        });
        break;

      // Process events are handled by onProcessLog
      case 'process_start':
      case 'decision_start':
      case 'decision_result':
      case 'decision_fallback':
      case 'decision_skip':
      case 'kb_search':
      case 'kb_result':
      case 'kb_skip':
      case 'chat_start':
      case 'chat_fallback':
      // Agentic mode events - falls through
      case 'agent_start':
      case 'agent_thinking':
      case 'agent_done':
      case 'agent_error':
      case 'agent_fallback':
      case 'agent_max_iterations':
      case 'tool_call':
      case 'tool_result':
      // Safety events - falls through
      case 'agent_safety_stop':
        // Already handled by onProcessLog above
        break;

      // Second AI events - already handled by onProcessLog above
      case 'second_ai_start':
      case 'second_ai_result':
        // Already handled by onProcessLog above
        break;
      case 'second_ai_modified':
        // Replace content with modified response
        if (parsed.content) {
          options.onContentReplace?.(parsed.content);
        }
        break;
    }
  } catch (e) {
    console.error('Failed to parse SSE data:', data, e);
  }
}

/**
 * Process remaining buffer (for incomplete events at end of stream)
 */
function processSSEBuffer(
  buffer: string,
  options: StreamOptions,
  onEventType?: (event: string) => void
): void {
  processSSEEvent(buffer, options, onEventType);
}

/**
 * Create an AbortController for stream cancellation
 */
export function createStreamAbortController(): AbortController {
  return new AbortController();
}
