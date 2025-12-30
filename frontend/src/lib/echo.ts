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
 * Create and configure Laravel Echo instance for Reverb WebSocket
 */
export const createEcho = (): Echo<'reverb'> => {
  const baseUrl = getBaseUrl();

  return new Echo({
    broadcaster: 'reverb',
    key: REVERB_KEY,
    wsHost: REVERB_HOST,
    wsPort: Number(REVERB_PORT),
    wssPort: Number(REVERB_PORT),
    forceTLS: REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${baseUrl}/api/broadcasting/auth`,
    // Use authorizer to get fresh token for each auth request
    // This prevents stale token issues after page refresh or rehydration
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
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
            callback(null, data);
          })
          .catch((error) => {
            console.error('Broadcasting auth error:', error);
            callback(error instanceof Error ? error : new Error(String(error)), null);
          });
      },
    }),
  });
};

// Singleton Echo instance
let echoInstance: Echo<'reverb'> | null = null;

/**
 * Get the Echo instance (creates one if it doesn't exist)
 */
export const getEcho = (): Echo<'reverb'> => {
  if (!echoInstance) {
    echoInstance = createEcho();
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

export default getEcho;
