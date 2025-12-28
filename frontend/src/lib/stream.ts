/**
 * Streaming API helper using Server-Sent Events (SSE)
 * Handles real-time AI responses with thinking tokens support
 */

export interface StreamEvent {
  event: 'thinking' | 'content' | 'error' | 'done';
  data: {
    text?: string;
    message?: string;
    status?: string;
  };
}

export interface StreamOptions {
  onThinking?: (text: string) => void;
  onContent?: (text: string) => void;
  onError?: (message: string) => void;
  onDone?: () => void;
  signal?: AbortSignal;
}

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';

/**
 * Stream AI response with extended thinking support.
 * Uses native fetch API for SSE streaming.
 */
export async function streamFlowTest(
  botId: number,
  flowId: number,
  message: string,
  conversationHistory: Array<{ role: 'user' | 'assistant'; content: string }>,
  enableThinking: boolean,
  options: StreamOptions
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
        enable_thinking: enableThinking,
      }),
      signal: options.signal,
    }
  );

  if (!response.ok) {
    const errorText = await response.text();
    throw new Error(`HTTP ${response.status}: ${errorText || response.statusText}`);
  }

  const reader = response.body?.getReader();
  if (!reader) {
    throw new Error('Response body is not readable');
  }

  const decoder = new TextDecoder();
  let buffer = '';

  try {
    while (true) {
      const { done, value } = await reader.read();

      if (done) {
        // Process any remaining buffer
        if (buffer.trim()) {
          processSSEBuffer(buffer, options);
        }
        break;
      }

      buffer += decoder.decode(value, { stream: true });

      // Process complete events (each event ends with \n\n)
      const events = buffer.split('\n\n');
      buffer = events.pop() || ''; // Keep incomplete event in buffer

      for (const eventBlock of events) {
        if (!eventBlock.trim()) continue;
        processSSEEvent(eventBlock, options);
      }
    }
  } finally {
    reader.releaseLock();
  }
}

/**
 * Process a complete SSE event block
 */
function processSSEEvent(eventBlock: string, options: StreamOptions): void {
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

    switch (eventType) {
      case 'thinking':
        if (parsed.text) {
          options.onThinking?.(parsed.text);
        }
        break;
      case 'content':
        if (parsed.text) {
          options.onContent?.(parsed.text);
        }
        break;
      case 'error':
        options.onError?.(parsed.message || 'Unknown error');
        break;
      case 'done':
        options.onDone?.();
        break;
    }
  } catch (e) {
    console.error('Failed to parse SSE data:', data, e);
  }
}

/**
 * Process remaining buffer (for incomplete events at end of stream)
 */
function processSSEBuffer(buffer: string, options: StreamOptions): void {
  // Try to process as event
  processSSEEvent(buffer, options);
}

/**
 * Create an AbortController for stream cancellation
 */
export function createStreamAbortController(): AbortController {
  return new AbortController();
}
