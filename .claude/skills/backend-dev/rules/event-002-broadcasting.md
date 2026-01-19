---
id: event-002-broadcasting
title: Broadcasting with Reverb
impact: HIGH
impactDescription: "Enables real-time updates via WebSockets"
category: event
tags: [event, broadcasting, websocket, reverb]
relatedRules: [event-001-dispatching]
---

## Why This Matters

Broadcasting sends events to clients via WebSockets for real-time updates. Without it, clients must poll for updates, causing delays and unnecessary server load.

## Bad Example

```php
// Problem: No real-time updates - client must poll
// Frontend polls every 5 seconds - wasteful and delayed
setInterval(() => fetchMessages(), 5000);
```

**Why it's wrong:**
- Delayed updates
- Wasted bandwidth
- Server load from polling
- Poor user experience

## Good Example

```php
// Event that broadcasts
// app/Events/MessageReceived.php
class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message
    ) {}

    // Channel to broadcast on
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversation.{$this->message->conversation_id}"),
        ];
    }

    // Data to send (optional - default is public properties)
    public function broadcastWith(): array
    {
        return [
            'message' => new MessageResource($this->message),
        ];
    }

    // Custom event name (optional)
    public function broadcastAs(): string
    {
        return 'message.received';
    }
}

// Channel authorization
// routes/channels.php
Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = Conversation::find($conversationId);
    return $conversation && $conversation->bot->user_id === $user->id;
});

// Dispatch broadcast
event(new MessageReceived($message));
// or
broadcast(new MessageReceived($message))->toOthers(); // Exclude sender
```

**Why it's better:**
- Instant updates
- No polling needed
- Efficient bandwidth
- Great UX

## Project-Specific Notes

**BotFacebook Broadcasting Setup:**

```php
// config/broadcasting.php
'default' => env('BROADCAST_DRIVER', 'reverb'),

'connections' => [
    'reverb' => [
        'driver' => 'reverb',
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'app_id' => env('REVERB_APP_ID'),
        'options' => [
            'host' => env('REVERB_HOST', 'localhost'),
            'port' => env('REVERB_PORT', 8080),
        ],
    ],
],
```

**Common Channels:**
```php
// Private user channel
new PrivateChannel("user.{$user->id}")

// Conversation updates
new PrivateChannel("conversation.{$conversation->id}")

// Bot activity
new PrivateChannel("bot.{$bot->id}.activity")
```

**Frontend (Laravel Echo):**
```typescript
Echo.private(`conversation.${conversationId}`)
    .listen('.message.received', (e) => {
        addMessage(e.message);
    });
```

## References

- [Laravel Broadcasting](https://laravel.com/docs/broadcasting)
- [Reverb Documentation](https://laravel.com/docs/reverb)
