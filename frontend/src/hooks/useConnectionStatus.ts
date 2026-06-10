import { useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useConnectionStore } from '@/stores/connectionStore';

/**
 * Hook to monitor WebSocket connection status and handle reconnection.
 *
 * Features:
 * - Checks current connection state on mount (handles case where Echo connected before listener attached)
 * - Listens for echo:connected, echo:disconnected, echo:reconnected, echo:resumed events
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
    let mounted = true;
    // Check current connection state immediately on mount
    // This handles the case where Echo connected before this listener was attached
    // Pusher states: initialized, connecting, connected, unavailable, failed, disconnected
    // Lazy-import echo so pusher-js stays out of the eager bundle (only needed post-auth).
    void import('@/lib/echo').then(({ getEcho }) => {
      if (!mounted) return;
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
    });

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
    // Fires when tab becomes visible; WebSocket may have stayed alive — always refetch.
    window.addEventListener('echo:resumed', handleReconnected);

    return () => {
      mounted = false;
      window.removeEventListener('echo:connected', handleConnected);
      window.removeEventListener('echo:disconnected', handleDisconnected);
      window.removeEventListener('echo:reconnected', handleReconnected);
      window.removeEventListener('echo:resumed', handleReconnected);
    };
  }, [queryClient, setConnected]);

  return { isConnected };
}
