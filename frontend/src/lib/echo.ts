import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Make Pusher available globally (required by Laravel Echo)
window.Pusher = Pusher;

// Environment configuration
const REVERB_KEY = import.meta.env.VITE_REVERB_APP_KEY || 'local-key';
const REVERB_HOST = import.meta.env.VITE_REVERB_HOST || 'localhost';
const REVERB_PORT = import.meta.env.VITE_REVERB_PORT || '8080';
const REVERB_SCHEME = import.meta.env.VITE_REVERB_SCHEME || 'http';

/**
 * Create and configure Laravel Echo instance for Reverb WebSocket
 */
export const createEcho = (): Echo<'reverb'> => {
  return new Echo({
    broadcaster: 'reverb',
    key: REVERB_KEY,
    wsHost: REVERB_HOST,
    wsPort: Number(REVERB_PORT),
    wssPort: Number(REVERB_PORT),
    forceTLS: REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${import.meta.env.VITE_API_URL || 'http://localhost:8000'}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${localStorage.getItem('auth_token') || ''}`,
      },
    },
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
