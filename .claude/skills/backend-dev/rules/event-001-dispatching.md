---
id: event-001-dispatching
title: Event Dispatching
impact: HIGH
impactDescription: "Enables loose coupling and extensible application architecture"
category: event
tags: [event, listener, architecture]
relatedRules: [event-002-broadcasting, eloquent-006-model-events]
---

## Why This Matters

Events decouple components - the code that triggers an action doesn't need to know all consequences. New features can be added by creating listeners without modifying existing code.

## Bad Example

```php
// Problem: Tightly coupled - controller knows all side effects
public function store(StoreBotRequest $request)
{
    $bot = Bot::create($request->validated());

    // Controller knows everything that should happen
    Log::info('Bot created', ['id' => $bot->id]);
    $this->notificationService->notifyUser($bot->user);
    $this->analyticsService->trackBotCreation($bot);
    $this->emailService->sendWelcomeEmail($bot->user);
    $this->slackService->notifyTeam("New bot: {$bot->name}");

    return new BotResource($bot);
}
```

**Why it's wrong:**
- Controller knows too much
- Adding features requires modifying controller
- Hard to test
- Tight coupling

## Good Example

```php
// Define event
// app/Events/BotCreated.php
class BotCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Bot $bot
    ) {}
}

// Define listeners
// app/Listeners/LogBotCreation.php
class LogBotCreation
{
    public function handle(BotCreated $event): void
    {
        Log::info('Bot created', [
            'bot_id' => $event->bot->id,
            'user_id' => $event->bot->user_id,
        ]);
    }
}

// app/Listeners/SendBotWelcomeEmail.php
class SendBotWelcomeEmail implements ShouldQueue
{
    public function handle(BotCreated $event): void
    {
        Mail::to($event->bot->user)->send(new BotWelcomeMail($event->bot));
    }
}

// Register in EventServiceProvider (or use attribute discovery)
protected $listen = [
    BotCreated::class => [
        LogBotCreation::class,
        SendBotWelcomeEmail::class,
        TrackBotAnalytics::class,
    ],
];

// Clean controller
public function store(StoreBotRequest $request): BotResource
{
    $bot = $this->service->create(auth()->user(), $request->validated());

    event(new BotCreated($bot));

    return new BotResource($bot);
}
```

**Why it's better:**
- Loose coupling
- Add features via new listeners
- Listeners can be queued
- Easy to test separately

## Project-Specific Notes

**BotFacebook Events:**
```
app/Events/
├── BotCreated.php
├── BotActivated.php
├── MessageReceived.php
├── MessageSent.php
├── ConversationStarted.php
└── ConversationEnded.php
```

**Dispatch Patterns:**
```php
// Simple dispatch
event(new BotCreated($bot));

// Dispatch helper
BotCreated::dispatch($bot);

// From model event
static::created(fn($bot) => event(new BotCreated($bot)));
```

**Queued Listeners:**
```php
class SendNotification implements ShouldQueue
{
    public $queue = 'notifications';
    public $delay = 60; // Delay in seconds
}
```

## References

- [Laravel Events](https://laravel.com/docs/events)
