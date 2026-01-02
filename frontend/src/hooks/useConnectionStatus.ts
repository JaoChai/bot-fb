import { useState, useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

/**
 * Hook to monitor WebSocket connection status and handle reconnection.
 *
 * Features:
 * - Listens for echo:connected, echo:disconnected, echo:reconnected events
 * - Shows toast notifications for connection status changes
 * - Auto-invalidates all queries on reconnect to fetch fresh data
 *
 * Usage: Call this hook once at the app level (e.g., RootLayout)
 */
export function useConnectionStatus() {
  const [isConnected, setIsConnected] = useState(true);
  const queryClient = useQueryClient();
  const hasConnectedOnce = useRef(false);

  useEffect(() => {
    const handleConnected = () => {
      setIsConnected(true);
      // Only show toast after initial connection (not on first load)
      if (hasConnectedOnce.current) {
        toast.success('เชื่อมต่อแล้ว', { id: 'connection-status' });
      }
      hasConnectedOnce.current = true;
    };

    const handleDisconnected = () => {
      setIsConnected(false);
      toast.error('ขาดการเชื่อมต่อ กำลังเชื่อมต่อใหม่...', {
        id: 'connection-status',
        duration: Infinity, // Stay until connected
      });
    };

    const handleReconnected = () => {
      // Invalidate ALL queries to fetch fresh data after reconnection
      // This ensures we don't miss any updates that happened while disconnected
      queryClient.invalidateQueries();
      toast.success('เชื่อมต่อใหม่แล้ว ข้อมูลอัพเดท', {
        id: 'connection-status',
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
  }, [queryClient]);

  return { isConnected };
}
