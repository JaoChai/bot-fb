---
id: ws-004-broadcast-events
title: Events Not Broadcasting
impact: MEDIUM
impactDescription: "Backend events don't reach frontend clients"
category: ws
tags: [websocket, broadcast, events, queue]
relatedRules: [ws-003-channel-subscription, queue-001-failed-jobs]
---

## Symptom

- Event fires but clients don't receive
- Queue worker running but no broadcasts
- Event logged in backend but not in Reverb
- Intermittent broadcast failures

## Root Cause

1. Event doesn't implement ShouldBroadcast
2. Queue connection not configured
3. Broadcast queue not processed
4. Serialization errors in event
5. Reverb not receiving from Laravel

## Diagnosis

### Quick Check

```php
// Check if event implements ShouldBroadcast
$event = new MessageReceived($message);
var_dump($event instanceof ShouldBroadcast); // Should be true

// Check queue connection
dd(config('broadcasting.connections.reverb'));
```

### Detailed Analysis

```php
// Add logging to event
class MessageReceived implements ShouldBroadcast
{
    public function __construct(Message $message)
    {
        $this->message = $message;
        Log::info('MessageReceived constructed', ['message_id' => $message->id]);
    }

    public function broadcastOn()
    {
        Log::info('Broadcasting on channels', ['channels' => $this->getChannelNames()]);
        return new PrivateChannel('bot.' . $this->message->bot_id);
    }
}

// Check failed broadcast jobs
php artisan queue:failed | grep -i broadcast
```

## Solution

### Fix Steps

1. **Implement ShouldBroadcast**
```php
// Must implement interface
class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // Event code...
}

// For immediate broadcast (no queue):
class MessageReceived implements ShouldBroadcastNow
{
    // ...
}
```

2. **Configure Broadcasting Queue**
```php
// config/broadcasting.php
'connections' => [
    'reverb' => [
        'driver' => 'reverb',
        'key' => env('REVERB_APP_KEY'),
        'secret' => env('REVERB_APP_SECRET'),
        'app_id' => env('REVERB_APP_ID'),
        'options' => [
            'host' => env('REVERB_HOST', '127.0.0.1'),
            'port' => env('REVERB_PORT', 8080),
            'scheme' => env('REVERB_SCHEME', 'http'),
        ],
    ],
],

// Events go to specific queue
class MessageReceived implements ShouldBroadcast
{
    public $broadcastQueue = 'broadcasts';
}
```

3. **Process Broadcast Queue**
```bash
# Run queue worker for broadcasts
php artisan queue:work --queue=broadcasts,default
```

### Code Example

```php
// Good: Robust broadcast event with error handling
namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Message $message;
    public $broadcastQueue = 'broadcasts';

    public function __construct(Message $message)
    {
        // Load needed relationships to avoid serialization issues
        $this->message = $message->loadMissing(['conversation', 'bot']);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('bot.' . $this->message->bot_id),
            new PrivateChannel('conversation.' . $this->message->conversation_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        // Only serialize necessary data
        return [
            'id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'content' => $this->message->content,
            'role' => $this->message->role,
            'metadata' => $this->message->metadata,
            'created_at' => $this->message->created_at->toISOString(),
        ];
    }

    public function broadcastWhen(): bool
    {
        // Don't broadcast system messages
        return $this->message->role !== 'system';
    }
}

// Helper to broadcast with error handling
class BroadcastService
{
    public function broadcastMessage(Message $message): void
    {
        try {
            broadcast(new MessageReceived($message));

            Log::debug('Message broadcast dispatched', [
                'message_id' => $message->id,
                'bot_id' => $message->bot_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Broadcast failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            // Don't throw - broadcast failure shouldn't break main flow
        }
    }
}
```

## Prevention

- Always verify ShouldBroadcast interface
- Use separate queue for broadcasts
- Monitor broadcast queue depth
- Test broadcasts in development
- Add broadcast health check

## Debug Commands

```bash
# Check broadcast configuration
php artisan config:show broadcasting

# Watch broadcast queue
php artisan queue:work --queue=broadcasts -v

# Test broadcast manually
php artisan tinker
>>> event(new App\Events\MessageReceived(App\Models\Message::first()));

# Check Reverb is receiving
# In Reverb logs, look for "Received message"
```

## Project-Specific Notes

**BotFacebook Context:**
- Broadcast events in `app/Events/`
- Queue: `broadcasts` (separate from main queue)
- `BroadcastService` handles dispatch
- Key events: `MessageReceived`, `ConversationCreated`, `BotStatusChanged`
