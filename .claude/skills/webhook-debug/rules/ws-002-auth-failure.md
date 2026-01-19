---
id: ws-002-auth-failure
title: WebSocket Authentication Fails
impact: HIGH
impactDescription: "Users cannot subscribe to private channels"
category: ws
tags: [websocket, authentication, sanctum, channels]
relatedRules: [ws-001-connection-drop, ws-003-channel-subscription]
---

## Symptom

- Cannot subscribe to private channels
- Console shows "Forbidden" or 403 error
- Public channels work but private don't
- Auth endpoint returns error

## Root Cause

1. Auth endpoint not configured
2. CSRF token missing
3. Sanctum token not sent
4. Broadcasting auth route misconfigured
5. Channel authorization fails

## Diagnosis

### Quick Check

```javascript
// Check auth endpoint response
fetch('/broadcasting/auth', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
    },
    body: JSON.stringify({
        socket_id: Echo.socketId(),
        channel_name: 'private-test',
    }),
}).then(r => r.json()).then(console.log);
```

### Detailed Analysis

```bash
# Check broadcasting routes
php artisan route:list | grep broadcast

# Check auth configuration
cat config/broadcasting.php

# Test auth endpoint
curl -X POST https://api.botjao.com/broadcasting/auth \
  -H "Authorization: Bearer {TOKEN}" \
  -d "socket_id=123.456" \
  -d "channel_name=private-bot.1"
```

## Solution

### Fix Steps

1. **Configure Auth Endpoint**
```php
// routes/channels.php
Broadcast::channel('bot.{botId}', function ($user, $botId) {
    return $user->bots()->where('id', $botId)->exists();
});

Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);
    return $conversation && $conversation->bot->user_id === $user->id;
});
```

2. **Configure Echo with Auth**
```javascript
window.Echo = new Echo({
    broadcaster: 'reverb',
    key: process.env.REVERB_APP_KEY,
    // ... other config
    authorizer: (channel) => ({
        authorize: (socketId, callback) => {
            axios.post('/broadcasting/auth', {
                socket_id: socketId,
                channel_name: channel.name,
            })
            .then(response => callback(null, response.data))
            .catch(error => callback(error, null));
        },
    }),
});
```

3. **Enable Broadcasting Auth Route**
```php
// routes/api.php or routes/channels.php
Broadcast::routes(['middleware' => ['auth:sanctum']]);
```

### Code Example

```php
// Good: Channel authorization with proper checks
// routes/channels.php

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Bot channel - user owns the bot
Broadcast::channel('bot.{botId}', function (User $user, int $botId) {
    $bot = Bot::find($botId);

    if (!$bot) {
        return false;
    }

    // Check ownership or team membership
    return $bot->user_id === $user->id
        || $user->teams()->whereHas('bots', fn($q) => $q->where('id', $botId))->exists();
});

// Conversation channel - user can access conversation
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::with('bot')->find($conversationId);

    if (!$conversation) {
        return false;
    }

    return $conversation->bot->user_id === $user->id;
});

// Presence channel - return user data
Broadcast::channel('bot.{botId}.presence', function (User $user, int $botId) {
    $bot = Bot::find($botId);

    if (!$bot || $bot->user_id !== $user->id) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'avatar' => $user->avatar_url,
    ];
});
```

```typescript
// Frontend: Echo configuration with auth
// frontend/src/lib/echo.ts

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { api } from './api';

window.Pusher = Pusher;

export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: parseInt(import.meta.env.VITE_REVERB_PORT || '8080'),
    wssPort: parseInt(import.meta.env.VITE_REVERB_PORT || '443'),
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
        authorize: (socketId: string, callback: Function) => {
            api.post('/broadcasting/auth', {
                socket_id: socketId,
                channel_name: channel.name,
            })
            .then(response => callback(null, response.data))
            .catch(error => {
                console.error('Channel auth failed:', channel.name, error);
                callback(error, null);
            });
        },
    }),
});
```

## Prevention

- Test channel authorization during development
- Log auth failures in backend
- Use consistent channel naming convention
- Document required permissions per channel
- Add auth health check endpoint

## Debug Commands

```bash
# Test channel auth directly
curl -X POST "https://api.botjao.com/broadcasting/auth" \
  -H "Authorization: Bearer {YOUR_TOKEN}" \
  -H "Content-Type: application/json" \
  -d '{"socket_id":"123.456","channel_name":"private-bot.1"}'

# Check channel definitions
cat routes/channels.php

# Debug in tinker
php artisan tinker
>>> Broadcast::channel('bot.1', fn($user) => true);
```

## Project-Specific Notes

**BotFacebook Context:**
- Auth endpoint: `/broadcasting/auth` (Sanctum protected)
- Channels defined in `routes/channels.php`
- Token from `useAuthStore().token`
- Debug with `REVERB_DEBUG=true` in .env
