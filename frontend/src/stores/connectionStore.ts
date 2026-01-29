import { create } from 'zustand';

interface ConnectionState {
  isConnected: boolean;
  setConnected: (connected: boolean) => void;
}

/**
 * Global store for WebSocket connection state.
 * Used by useConnectionStatus to update state and by conversation hooks
 * to enable fallback polling when disconnected.
 *
 * IMPORTANT: Start with isConnected: false because:
 * - Pusher.js starts in 'initialized' state, not 'connected'
 * - This enables fallback polling until WebSocket actually connects
 * - Prevents stale data on initial load
 */
export const useConnectionStore = create<ConnectionState>((set) => ({
  isConnected: false, // Start disconnected, wait for actual connection
  setConnected: (isConnected) => set({ isConnected }),
}));
