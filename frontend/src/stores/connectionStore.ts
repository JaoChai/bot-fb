import { create } from 'zustand';

interface ConnectionState {
  isConnected: boolean;
  setConnected: (connected: boolean) => void;
}

/**
 * Global store for WebSocket connection state.
 * Used by useConnectionStatus to update state and by conversation hooks
 * to enable fallback polling when disconnected.
 */
export const useConnectionStore = create<ConnectionState>((set) => ({
  isConnected: true, // Assume connected initially
  setConnected: (isConnected) => set({ isConnected }),
}));
