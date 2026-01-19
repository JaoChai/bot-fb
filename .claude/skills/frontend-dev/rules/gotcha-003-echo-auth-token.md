---
id: gotcha-003-echo-auth-token
title: Laravel Echo Authentication Token
impact: HIGH
impactDescription: "Ensures WebSocket connections authenticate correctly for real-time features"
category: gotcha
tags: [websocket, echo, reverb, authentication, real-time]
relatedRules: []
---

## Why This Matters

BotFacebook uses Laravel Reverb for real-time features (live chat, notifications, presence). Echo needs a valid Sanctum token to authenticate with private channels. Without proper token setup, WebSocket connections fail silently and real-time features don't work.

This is particularly tricky because Echo initializes early in the app lifecycle, potentially before the auth token is available.

## Bad Example

```tsx
// Problem 1: Echo initialized without auth token
// lib/echo.ts
import Echo from 'laravel-echo';

window.Echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  // Missing authorizer with token!
});

// Problem 2: Using stale token
const echo = new Echo({
  broadcaster: 'reverb',
  authEndpoint: '/api/broadcasting/auth',
  auth: {
    headers: {
      Authorization: `Bearer ${localStorage.getItem('token')}`, // Evaluated once at init!
    },
  },
});
```

**Why it's wrong:**
- Echo without authorizer cannot join private/presence channels
- Static token from localStorage is captured at initialization time
- If user logs in after Echo init, the token won't update
- WebSocket connections fail silently - no error in console

## Good Example

```tsx
// Solution: Dynamic token in authorizer function
// lib/echo.ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useAuthStore } from '@/stores/auth';

window.Pusher = Pusher;

export function initializeEcho() {
  window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel) => ({
      authorize: (socketId, callback) => {
        // Get fresh token on each authorization attempt
        const token = useAuthStore.getState().token;

        fetch('/api/broadcasting/auth', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`,
          },
          body: JSON.stringify({
            socket_id: socketId,
            channel_name: channel.name,
          }),
        })
          .then(response => response.json())
          .then(data => callback(null, data))
          .catch(error => callback(error, null));
      },
    }),
  });
}

// Re-initialize when user logs in
function useAuthSync() {
  const token = useAuthStore(state => state.token);

  useEffect(() => {
    if (token) {
      initializeEcho();
    }
  }, [token]);
}
```

**Why it's better:**
- `authorizer` function is called for each channel subscription
- Token is fetched fresh from store at authorization time
- Re-initializing Echo on login ensures fresh connection
- Explicit error handling in authorize callback

## Project-Specific Notes

**Key Files:**
- `frontend/src/lib/echo.ts` - Echo initialization
- `frontend/src/stores/auth.ts` - Auth store with token
- `frontend/src/hooks/useConversationChannel.ts` - Channel subscription hooks

**Debugging Tips:**
```tsx
// Check if Echo is connected
console.log('Echo connector:', window.Echo?.connector);
console.log('Socket ID:', window.Echo?.socketId());

// Test channel subscription
window.Echo.private('conversation.123')
  .listen('.message.created', (e) => console.log('Message:', e))
  .error((e) => console.error('Channel error:', e));
```

**Environment Variables Required:**
```env
VITE_REVERB_APP_KEY=your-app-key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

## References

- [Laravel Echo Authentication](https://laravel.com/docs/broadcasting#authorizing-channels)
- [Reverb Documentation](https://laravel.com/docs/reverb)
