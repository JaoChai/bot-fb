# WebSocket (Reverb/Echo) Debugging

## Architecture

```
Browser ←→ Laravel Echo ←→ Reverb Server ←→ Laravel Backend
                              (wss://reverb.botjao.com)
```

## Setup Verification

### 1. Check Reverb Configuration

```php
// config/broadcasting.php
'reverb' => [
    'driver' => 'reverb',
    'key' => env('REVERB_APP_KEY'),
    'secret' => env('REVERB_APP_SECRET'),
    'app_id' => env('REVERB_APP_ID'),
    'options' => [
        'host' => env('REVERB_HOST', 'localhost'),
        'port' => env('REVERB_PORT', 8080),
        'scheme' => env('REVERB_SCHEME', 'https'),
    ],
],
```

### 2. Verify Environment Variables

```bash
# Required variables
REVERB_APP_ID=your_app_id
REVERB_APP_KEY=your_app_key
REVERB_APP_SECRET=your_app_secret
REVERB_HOST=reverb.botjao.com
REVERB_PORT=443
REVERB_SCHEME=https
```

### 3. Check Connection in Browser

```javascript
// Browser console
console.log(Echo.connector.pusher.connection.state);
// Should be: 'connected'

// Listen for connection events
Echo.connector.pusher.connection.bind('connected', () => {
    console.log('WebSocket connected!');
});

Echo.connector.pusher.connection.bind('error', (error) => {
    console.error('WebSocket error:', error);
});
```

## Laravel Echo Setup

### Frontend Configuration

```typescript
// lib/echo.ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: true,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/api/broadcasting/auth',
    auth: {
        headers: {
            Authorization: `Bearer ${getToken()}`,
        },
    },
});
```

### Subscribing to Channels

```typescript
// Public channel
echo.channel('notifications')
    .listen('NewNotification', (e) => {
        console.log('Notification:', e);
    });

// Private channel (requires auth)
echo.private(`conversation.${conversationId}`)
    .listen('MessageReceived', (e) => {
        console.log('Message:', e.message);
    });

// Presence channel (shows who's online)
echo.join(`chat.${roomId}`)
    .here((users) => console.log('Online:', users))
    .joining((user) => console.log('Joined:', user))
    .leaving((user) => console.log('Left:', user))
    .listen('ChatMessage', (e) => console.log(e));
```

## Broadcasting Events

### Event Class

```php
<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversation.{$this->message->conversation_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->id,
                'content' => $this->message->content,
                'created_at' => $this->message->created_at->toISOString(),
            ],
        ];
    }

    public function broadcastAs(): string
    {
        return 'MessageReceived'; // Event name in JS
    }
}
```

### Broadcasting

```php
// Dispatch event (queued)
broadcast(new MessageReceived($message));

// Dispatch immediately
broadcast(new MessageReceived($message))->toOthers();

// Don't send to current user
broadcast(new MessageReceived($message))->toOthers();
```

## Common Issues

### Connection Failed

**Symptoms:**
- `WebSocket connection failed`
- State stuck at 'connecting'

**Causes & Fixes:**

| Cause | Fix |
|-------|-----|
| Wrong host/port | Check REVERB_HOST, REVERB_PORT |
| SSL certificate | Ensure valid HTTPS |
| CORS issue | Add domain to allowed origins |
| Firewall | Allow WebSocket traffic |

### Authentication Failed

**Symptoms:**
- `Channel subscription failed`
- 403 errors on auth endpoint

**Debug:**
```javascript
// Check auth endpoint
fetch('/api/broadcasting/auth', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify({
        socket_id: 'xxx',
        channel_name: 'private-conversation.1',
    }),
});
```

**Fix:**
```php
// routes/channels.php
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);
    return $conversation && $conversation->hasParticipant($user);
});
```

### Events Not Received

**Symptoms:**
- Backend broadcasts, frontend doesn't receive

**Debug Steps:**

1. **Check event was dispatched:**
```php
Log::info('Broadcasting event', [
    'event' => get_class($event),
    'channel' => $event->broadcastOn(),
]);
```

2. **Check queue is running:**
```bash
php artisan queue:work --queue=default
```

3. **Check subscription:**
```javascript
const channel = echo.private(`conversation.${id}`);
console.log('Subscribed to:', channel.name);

channel.subscribed(() => {
    console.log('Successfully subscribed!');
});
```

4. **Check event name matches:**
```javascript
// Laravel: broadcastAs() returns 'MessageReceived'
// JS must listen for same name:
.listen('MessageReceived', handler)  // ✅
.listen('message-received', handler) // ❌
```

### Race Conditions

**Symptom:** Unread count incorrect, messages duplicated

**Cause:** Multiple WebSocket updates without proper state management

**Fix:**
```typescript
// Use React Query for cache management
const queryClient = useQueryClient();

echo.private(`conversation.${id}`)
    .listen('MessageReceived', (e) => {
        // Optimistic update
        queryClient.setQueryData(['messages', id], (old) => ({
            ...old,
            data: [...(old?.data || []), e.message],
        }));

        // Invalidate to ensure consistency
        queryClient.invalidateQueries({ queryKey: ['messages', id] });
    });
```

## Debugging Commands

### Check Reverb Status

```bash
# Railway logs for Reverb
railway logs --service reverb --lines 100

# Check if Reverb is accepting connections
curl -I https://reverb.botjao.com
```

### Test Broadcast Locally

```php
// Tinker test
broadcast(new \App\Events\TestEvent('Hello World'));
```

### Monitor WebSocket Traffic

```javascript
// Enable Pusher logging
Pusher.logToConsole = true;

// Or in Echo config
const echo = new Echo({
    // ...
    enableLogging: true,
});
```

## Checklist

- [ ] Reverb server is running
- [ ] Environment variables set correctly
- [ ] SSL certificate valid
- [ ] Auth endpoint returns 200 for valid users
- [ ] Channel authorization rules defined
- [ ] Queue worker running for broadcast
- [ ] Event broadcastAs() matches JS listener
- [ ] No CORS errors in browser console
