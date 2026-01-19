---
id: pattern-001-strategy-pattern
title: Strategy Pattern Refactoring
impact: HIGH
impactDescription: "Replace conditional logic with interchangeable strategies"
category: pattern
tags: [design-pattern, strategy, polymorphism, extensibility]
relatedRules: [smell-003-complex-conditional, laravel-002-extract-service]
---

## Code Smell

- Switch/if on type to choose behavior
- Same conditional in multiple places
- Adding new types requires modifying existing code
- Violates Open/Closed principle
- Hard to test different behaviors

## Root Cause

1. Started with 2 cases, grew to many
2. No abstraction planned
3. Quick type-checking solution
4. Unfamiliar with pattern
5. Fear of "over-engineering"

## When to Apply

**Apply when:**
- 3+ conditional branches on same type
- Same switch/if repeated
- New types added frequently
- Need to test behaviors independently
- Behaviors are complex (> 10 lines each)

**Don't apply when:**
- Only 2 simple cases
- Conditions unlikely to grow
- Would add unnecessary complexity

## Solution

### Before (Conditional Branching)

```php
class MessageService
{
    public function sendMessage(Bot $bot, string $content): void
    {
        if ($bot->platform === 'line') {
            $this->lineApi->setChannelToken($bot->channel_token);
            $response = $this->lineApi->pushMessage([
                'to' => $bot->channel_id,
                'messages' => [['type' => 'text', 'text' => $content]],
            ]);
            Log::info('LINE message sent', ['response' => $response]);

        } elseif ($bot->platform === 'telegram') {
            $this->telegramApi->setToken($bot->bot_token);
            $response = $this->telegramApi->sendMessage([
                'chat_id' => $bot->chat_id,
                'text' => $content,
                'parse_mode' => 'HTML',
            ]);
            Log::info('Telegram message sent', ['response' => $response]);

        } elseif ($bot->platform === 'facebook') {
            $this->facebookApi->setAccessToken($bot->page_token);
            $response = $this->facebookApi->sendMessage(
                $bot->page_id,
                $bot->recipient_id,
                ['text' => $content]
            );
            Log::info('Facebook message sent', ['response' => $response]);

        } else {
            throw new UnsupportedPlatformException($bot->platform);
        }
    }

    public function getMessageHistory(Bot $bot, int $limit): array
    {
        // Same pattern repeats!
        if ($bot->platform === 'line') {
            // LINE-specific code
        } elseif ($bot->platform === 'telegram') {
            // Telegram-specific code
        } elseif ($bot->platform === 'facebook') {
            // Facebook-specific code
        }
    }
}
```

### After (Strategy Pattern)

```php
// Step 1: Define interface
interface PlatformMessenger
{
    public function send(Bot $bot, string $content): void;
    public function getHistory(Bot $bot, int $limit): array;
    public function supports(string $platform): bool;
}

// Step 2: Implement strategies
class LineMessenger implements PlatformMessenger
{
    public function __construct(
        private LineApiClient $api
    ) {}

    public function send(Bot $bot, string $content): void
    {
        $this->api->setChannelToken($bot->channel_token);

        $response = $this->api->pushMessage([
            'to' => $bot->channel_id,
            'messages' => [['type' => 'text', 'text' => $content]],
        ]);

        Log::info('LINE message sent', ['response' => $response]);
    }

    public function getHistory(Bot $bot, int $limit): array
    {
        // LINE-specific implementation
    }

    public function supports(string $platform): bool
    {
        return $platform === 'line';
    }
}

class TelegramMessenger implements PlatformMessenger
{
    public function __construct(
        private TelegramApiClient $api
    ) {}

    public function send(Bot $bot, string $content): void
    {
        $this->api->setToken($bot->bot_token);

        $response = $this->api->sendMessage([
            'chat_id' => $bot->chat_id,
            'text' => $content,
            'parse_mode' => 'HTML',
        ]);

        Log::info('Telegram message sent', ['response' => $response]);
    }

    public function getHistory(Bot $bot, int $limit): array
    {
        // Telegram-specific implementation
    }

    public function supports(string $platform): bool
    {
        return $platform === 'telegram';
    }
}

// Step 3: Create factory/resolver
class MessengerFactory
{
    public function __construct(
        private array $messengers
    ) {}

    public function make(string $platform): PlatformMessenger
    {
        foreach ($this->messengers as $messenger) {
            if ($messenger->supports($platform)) {
                return $messenger;
            }
        }

        throw new UnsupportedPlatformException($platform);
    }
}

// Step 4: Register in service provider
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MessengerFactory::class, function ($app) {
            return new MessengerFactory([
                $app->make(LineMessenger::class),
                $app->make(TelegramMessenger::class),
                $app->make(FacebookMessenger::class),
            ]);
        });
    }
}

// Step 5: Clean service
class MessageService
{
    public function __construct(
        private MessengerFactory $messengerFactory
    ) {}

    public function sendMessage(Bot $bot, string $content): void
    {
        $messenger = $this->messengerFactory->make($bot->platform);
        $messenger->send($bot, $content);
    }

    public function getMessageHistory(Bot $bot, int $limit): array
    {
        $messenger = $this->messengerFactory->make($bot->platform);
        return $messenger->getHistory($bot, $limit);
    }
}
```

### Laravel-Specific: Using Config Binding

```php
// config/platforms.php
return [
    'messengers' => [
        'line' => \App\Services\Messengers\LineMessenger::class,
        'telegram' => \App\Services\Messengers\TelegramMessenger::class,
        'facebook' => \App\Services\Messengers\FacebookMessenger::class,
    ],
];

// MessengerFactory.php
class MessengerFactory
{
    public function make(string $platform): PlatformMessenger
    {
        $class = config("platforms.messengers.{$platform}");

        if (!$class) {
            throw new UnsupportedPlatformException($platform);
        }

        return app($class);
    }
}
```

## Step-by-Step

1. **Identify varying behavior**
   - What changes based on type?
   - What's the common interface?

2. **Define interface**
   ```php
   interface Strategy
   {
       public function execute(Context $context): Result;
   }
   ```

3. **Extract strategies**
   - One class per type
   - Implement interface

4. **Create factory**
   - Maps type → strategy
   - Returns correct instance

5. **Replace conditionals**
   - Use factory to get strategy
   - Call strategy method

6. **Register in container**
   - Bind strategies
   - Inject factory

## Verification

```bash
# Check no conditionals remain
grep -n "if.*platform.*===" app/Services/MessageService.php
# Should return nothing

# Verify all strategies implement interface
grep -l "implements PlatformMessenger" app/Services/Messengers/
```

## Benefits

- **Open/Closed**: Add new platforms without modifying existing code
- **Single Responsibility**: Each strategy handles one platform
- **Testable**: Mock individual strategies
- **Extensible**: Easy to add new behaviors

## Project-Specific Notes

**BotFacebook Context:**
- Platform switching: LINE, Telegram, Facebook
- Model selection: OpenRouter models
- Embedding providers: OpenAI, Voyage
- Location: `app/Services/{Domain}/` for strategies
