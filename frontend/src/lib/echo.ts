import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Make Pusher available globally (required by Laravel Echo)
window.Pusher = Pusher;

// Environment configuration
const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const REVERB_KEY = import.meta.env.VITE_REVERB_APP_KEY || 'local-key';
const REVERB_HOST = import.meta.env.VITE_REVERB_HOST || 'localhost';
const REVERB_PORT = import.meta.env.VITE_REVERB_PORT || '8080';
const REVERB_SCHEME = import.meta.env.VITE_REVERB_SCHEME || 'http';

/**
 * Get the base URL for API calls (removes /api suffix if present)
 */
const getBaseUrl = (): string => {
  const url = API_URL.endsWith('/api') ? API_URL.slice(0, -4) : API_URL;
  return url.endsWith('/') ? url.slice(0, -1) : url;
};

/**
 * Auth cache to prevent redundant auth requests
 * Cache expires after 5 minutes
 */
interface AuthCacheEntry {
  auth: string;
  timestamp: number;
  socketId: string;
}
const authCache = new Map<string, AuthCacheEntry>();
const AUTH_CACHE_TTL = 5 * 60 * 1000; // 5 minutes

const getCachedAuth = (channelName: string, socketId: string): { auth: string } | null => {
  const entry = authCache.get(channelName);
  if (!entry) return null;
  // Check if cache is still valid (same socketId and not expired)
  if (entry.socketId === socketId && Date.now() - entry.timestamp < AUTH_CACHE_TTL) {
    return { auth: entry.auth };
  }
  // Cache expired or socketId changed
  authCache.delete(channelName);
  return null;
};

const setCachedAuth = (channelName: string, socketId: string, auth: string): void => {
  authCache.set(channelName, { auth, timestamp: Date.now(), socketId });
};

/**
 * Clear auth cache (call on logout or token refresh)
 */
const clearAuthCache = (): void => {
  authCache.clear();
};

/**
 * Create and configure Laravel Echo instance for Reverb WebSocket
 */
const createEcho = (): Echo<'reverb'> => {
  const baseUrl = getBaseUrl();

  const echo = new Echo({
    broadcaster: 'reverb',
    key: REVERB_KEY,
    wsHost: REVERB_HOST,
    wsPort: Number(REVERB_PORT),
    wssPort: Number(REVERB_PORT),
    forceTLS: REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${baseUrl}/api/broadcasting/auth`,
    // Keep-alive settings to prevent premature disconnection
    // Reverb pings every 25s; 30s buffer detects drops in ~40s total
    activityTimeout: 30000,   // 30s - shorter than previous 120s for faster drop detection
    pongTimeout: 10000,       // 10s - faster pong-failure detection (was 30s)
    // Use authorizer with caching to reduce redundant auth requests
    // Cache expires after 5 minutes or when socketId changes
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
        // Check cache first
        const cached = getCachedAuth(channel.name, socketId);
        if (cached) {
          callback(null, cached);
          return;
        }

        const token = localStorage.getItem('auth_token');
        fetch(`${baseUrl}/api/broadcasting/auth`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${token || ''}`,
          },
          body: JSON.stringify({
            socket_id: socketId,
            channel_name: channel.name,
          }),
        })
          .then((response) => {
            if (!response.ok) {
              throw new Error(`Auth failed: ${response.status}`);
            }
            return response.json();
          })
          .then((data) => {
            // Cache successful auth
            if (data?.auth) {
              setCachedAuth(channel.name, socketId, data.auth);
            }
            callback(null, data);
          })
          .catch((error) => {
            console.error('Broadcasting auth error:', error);
            callback(error instanceof Error ? error : new Error(String(error)), null);
          });
      },
    }),
  });

  // Monitor connection state for automatic recovery
  // States: initialized, connecting, connected, unavailable, failed, disconnected
  echo.connector.pusher.connection.bind('state_change', (states: {
    previous: string;
    current: string;
  }) => {
    // Dispatch events for global listeners
    if (states.current === 'connected') {
      window.dispatchEvent(new CustomEvent('echo:connected'));

      // Reconnected (not initial connection) - need to refetch data!
      if (states.previous !== 'initialized') {
        window.dispatchEvent(new CustomEvent('echo:reconnected'));
      }
    } else if (
      states.current === 'disconnected' ||
      states.current === 'unavailable' ||
      states.current === 'failed'
    ) {
      window.dispatchEvent(new CustomEvent('echo:disconnected', {
        detail: { state: states.current }
      }));
    }
  });

  return echo;
};

// Singleton Echo instance
let echoInstance: Echo<'reverb'> | null = null;

// Module-level visibility handler — runs once on first import.
// On tab becoming visible: reconnect Echo if disconnected, and ALWAYS dispatch
// echo:resumed so consumers (useConnectionStatus, useRealtime) can refetch
// stale data even when the WebSocket itself stayed alive.
if (typeof document !== 'undefined') {
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;

    const echo = echoInstance;
    if (echo) {
      const state = echo.connector.pusher.connection.state;
      if (state !== 'connected' && state !== 'connecting') {
        echo.connector.pusher.connect();
      }
    }

    window.dispatchEvent(new CustomEvent('echo:resumed'));
  });
}

/**
 * Get the Echo instance (creates one if it doesn't exist)
 * Also exposes instance to window for debugging
 */
export const getEcho = (): Echo<'reverb'> => {
  if (!echoInstance) {
    echoInstance = createEcho();
    // Expose to window for debugging
    if (typeof window !== 'undefined') {
      (window as Window & { Echo?: Echo<'reverb'> }).Echo = echoInstance;
    }
  }
  return echoInstance;
};

/**
 * Disconnect and reset the Echo instance
 */
export const disconnectEcho = (): void => {
  if (echoInstance) {
    echoInstance.disconnect();
    echoInstance = null;
  }
  clearAuthCache(); // Clear auth cache on disconnect
};

/**
 * Reconnect Echo with fresh auth token
 */
export const reconnectEcho = (): Echo<'reverb'> => {
  disconnectEcho();
  return getEcho();
};

// Type definitions for Pusher on window
declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

