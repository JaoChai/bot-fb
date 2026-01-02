import { useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useConnectionStore } from '@/stores/connectionStore';

/**
 * Hook to monitor WebSocket connection status and handle reconnection.
 *
 * Features:
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
    const handleConnected = () => {
      setConnected(true);
      hasConnectedOnce.current = true;
    };

    const handleDisconnected = () => {
      setConnected(false);
      // Log to console for debugging, but don't show toast
      console.log('[WebSocket] Disconnected, attempting to reconnect...');
    };

    const handleReconnected = () => {
      // Invalidate ALL queries to fetch fresh data after reconnection
      // This ensures we don't miss any updates that happened while disconnected
      queryClient.invalidateQueries();
      console.log('[WebSocket] Reconnected, data refreshed');
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
