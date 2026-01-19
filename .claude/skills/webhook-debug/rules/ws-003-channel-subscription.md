---
id: ws-003-channel-subscription
title: WebSocket Channel Subscription Fails
impact: MEDIUM
impactDescription: "Specific channels don't receive events"
category: ws
tags: [websocket, channels, subscription, events]
relatedRules: [ws-002-auth-failure, ws-004-broadcast-events]
---

## Symptom

- Subscribed to channel but no events received
- Some channels work, others don't
- No errors but events missing
- Channel shows as subscribed but silent

## Root Cause

1. Channel name mismatch (backend vs frontend)
2. Wrong channel type (public vs private)
3. Event name mismatch
4. Broadcasting not configured for event
5. Channel prefix issues

## Diagnosis

### Quick Check

```javascript
// Check subscribed channels
Echo.connector.pusher.allChannels();

// Check specific channel subscription
const channel = Echo.private('bot.1');
console.log('Subscribed:', channel.subscription.subscribed);

// Listen for all events on channel
channel.listenToAll((event, data) => {
    console.log('Event:', event, data);
});
```

### Detailed Analysis

```php
// Log broadcast events
// In BroadcastServiceProvider or event class
Log::debug('Broadcasting event', [
    'event' => get_class($event),
    'channel' => $event->broadcastOn(),
    'data' => $event->broadcastWith(),
]);
```

## Solution

### Fix Steps

1. **Verify Channel Name Format**
```php
// Backend: Channel definition
class MessageReceived implements ShouldBroadcast
{
    public function broadcastOn(): Channel
    {
        // Use PrivateChannel for authenticated users
        return new PrivateChannel('bot.' . $this->message->bot_id);
    }
}

// Frontend: Subscribe with matching name
// Note: 'private-' prefix is added automatically
Echo.private('bot.1').listen('MessageReceived', (e) => {
    console.log(e);
});
```

2. **Verify Event Name**
```php
// Backend: Event class
class MessageReceived implements ShouldBroadcast
{
    // By default, broadcasts as class name 'MessageReceived'
    // Or customize:
    public function broadcastAs(): string
    {
        return 'message.received';
    }
}

// Frontend: Match the event name
// If using broadcastAs:
Echo.private('bot.1').listen('.message.received', (e) => {});
// If using default class name:
Echo.private('bot.1').listen('MessageReceived', (e) => {});
```

3. **Check Broadcast Configuration**
```php
// Event must implement ShouldBroadcast
class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('bot.' . $this->message->bot_id);
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'content' => $this->message->content,
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }
}
```

### Code Example

```php
// Good: Complete broadcast event
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
            // Bot channel for dashboard updates
            new PrivateChannel('bot.' . $this->message->bot_id),
            // Conversation channel for chat view
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'content' => $this->message->content,
            'role' => $this->message->role,
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }

    // Optional: Exclude sender from receiving
    public function broadcastWhen(): bool
    {
        return true;
    }
}
```

```typescript
// Frontend: Proper channel subscription
// frontend/src/hooks/useBotChannel.ts

export function useBotChannel(botId: number) {
    const [messages, setMessages] = useState<Message[]>([]);

    useEffect(() => {
        // Subscribe to bot channel
        const channel = echo.private(`bot.${botId}`);

        // Listen for message events
        // Note: '.' prefix for custom broadcastAs names
        channel.listen('.message.received', (event: MessageEvent) => {
            setMessages(prev => [...prev, event]);
        });

        // Listen for typing events
        channel.listen('.user.typing', (event: TypingEvent) => {
            // Handle typing indicator
        });

        // Debug: listen to all events
        if (import.meta.env.DEV) {
            channel.listenToAll((eventName, data) => {
                console.log(`[Bot ${botId}] Event:`, eventName, data);
            });
        }

        return () => {
            echo.leave(`bot.${botId}`);
        };
    }, [botId]);

    return messages;
}
```

## Prevention

- Use consistent channel naming convention
- Document channel/event mappings
- Add integration tests for broadcasts
- Log all broadcast events in development
- Use typed event payloads

## Debug Commands

```bash
# List all events
php artisan event:list

# Test broadcast manually
php artisan tinker
>>> broadcast(new \App\Events\MessageReceived(Message::first()));

# Check Reverb received the broadcast
tail -f storage/logs/reverb.log | grep broadcast
```

## Project-Specific Notes

**BotFacebook Context:**
- Channels: `bot.{id}`, `conversation.{id}`
- Events: `MessageReceived`, `ConversationUpdated`, `BotStatusChanged`
- Hooks: `useBotChannel()`, `useConversationChannel()`
- Always use `.` prefix for custom event names
