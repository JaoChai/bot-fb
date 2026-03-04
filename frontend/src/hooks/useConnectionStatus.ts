import { useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useConnectionStore } from '@/stores/connectionStore';
import { getEcho } from '@/lib/echo';

/**
 * Hook to monitor WebSocket connection status and handle reconnection.
 *
 * Features:
 * - Checks current connection state on mount (handles case where Echo connected before listener attached)
 * - Listens for echo:connected, echo:disconnected, echo:reconnected events
 * - Auto-invalidates all queries on reconnect to fetch fresh data
 * - Updates global connection state in Zustand store for fallback polling
 *
 * Note: Toast notifications removed to reduce UI noise during frequent reconnects
 *
 * Usage: Call this hook once at the app level (e.g., RootLayout)
 */
export function useConnectionStatus() {
  const queryClient = useQueryClient();
  const hasConnectedOnce = useRef(false);
  const { isConnected, setConnected } = useConnectionStore();

  useEffect(() => {
    // Check current connection state immediately on mount
    // This handles the case where Echo connected before this listener was attached
    // Pusher states: initialized, connecting, connected, unavailable, failed, disconnected
    try {
      const echo = getEcho();
      const currentState = echo.connector?.pusher?.connection?.state;
      if (currentState === 'connected') {
        setConnected(true);
        hasConnectedOnce.current = true;
      }
    } catch {
      // Echo not initialized yet, will get connected event later
    }

    const handleConnected = () => {
      setConnected(true);
      hasConnectedOnce.current = true;
    };

    const handleDisconnected = () => {
      setConnected(false);
    };

    const handleReconnected = () => {
      queryClient.invalidateQueries({
        predicate: (query) => {
          const key = query.queryKey;
          return Array.isArray(key) &&
            typeof key[0] === 'string' &&
            ['conversations-infinite', 'messages', 'conversation', 'conversation-detail', 'conversation-stats'].includes(key[0]);
        },
      });
    };

    window.addEventListener('echo:connected', handleConnected);
    window.addEventListener('echo:disconnected', handleDisconnected);
    window.addEventListener('echo:reconnected', handleReconnected);

    return () => {
      window.removeEventListener('echo:connected', handleConnected);
      window.removeEventListener('echo:disconnected', handleDisconnected);
      window.removeEventListener('echo:reconnected', handleReconnected);
    };
  }, [queryClient, setConnected]);

  return { isConnected };
}
