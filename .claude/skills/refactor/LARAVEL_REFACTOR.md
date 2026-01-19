# Laravel Refactoring Guide

Laravel 12 + PHP 8.4 refactoring patterns.

## Extract Service from Controller

### Before (Fat Controller)
```php
class BotController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([...]);

        // 50+ lines of business logic here
        $bot = Bot::create($validated);
        $bot->settings()->create([...]);
        event(new BotCreated($bot));
        // ... more logic

        return response()->json($bot);
    }
}
```

### After (Thin Controller + Service)
```php
// Controller
class BotController extends Controller
{
    public function __construct(
        private BotService $botService
    ) {}

    public function store(StoreBotRequest $request): BotResource
    {
        $bot = $this->botService->create($request->validated());
        return new BotResource($bot);
    }
}

// Service
class BotService
{
    public function create(array $data): Bot
    {
        return DB::transaction(function () use ($data) {
            $bot = Bot::create($data);
            $bot->settings()->create($this->defaultSettings());
            event(new BotCreated($bot));
            return $bot;
        });
    }
}
```

## Extract Query Scope

### Before (Repeated Where Clauses)
```php
// In multiple places
Bot::where('user_id', $userId)
    ->where('is_active', true)
    ->where('platform', 'line')
    ->get();
```

### After (Model Scope)
```php
// In Model
class Bot extends Model
{
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform(Builder $query, string $platform): Builder
    {
        return $query->where('platform', $platform);
    }
}

// Usage
Bot::forUser($userId)->active()->platform('line')->get();
```

## Extract FormRequest

### Before (Inline Validation)
```php
public function store(Request $request)
{
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'platform' => ['required', Rule::in(['line', 'telegram'])],
        'webhook_url' => ['nullable', 'url'],
        // ... 20 more rules
    ]);
}
```

### After (FormRequest Class)
```php
// app/Http/Requests/StoreBotRequest.php
class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['line', 'telegram'])],
            'webhook_url' => ['nullable', 'url'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Bot name is required',
        ];
    }
}

// Controller
public function store(StoreBotRequest $request): BotResource
{
    // $request->validated() is already validated
}
```

## Extract Trait

### Before (Duplicate Methods)
```php
class Bot extends Model
{
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'active' => 'success',
            'inactive' => 'warning',
            default => 'secondary',
        };
    }
}

class Conversation extends Model
{
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'active' => 'success',
            'inactive' => 'warning',
            default => 'secondary',
        };
    }
}
```

### After (Shared Trait)
```php
// app/Models/Traits/HasStatusBadge.php
trait HasStatusBadge
{
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'active' => 'success',
            'inactive' => 'warning',
            default => 'secondary',
        };
    }
}

// Models
class Bot extends Model
{
    use HasStatusBadge;
}

class Conversation extends Model
{
    use HasStatusBadge;
}
```

## Extract Action Class

### Before (Complex Single-Use Logic)
```php
class BotService
{
    public function processWebhook(Bot $bot, array $payload): void
    {
        // 100+ lines for one specific operation
    }
}
```

### After (Dedicated Action)
```php
// app/Actions/ProcessWebhookAction.php
class ProcessWebhookAction
{
    public function __construct(
        private MessageParser $parser,
        private ResponseBuilder $builder
    ) {}

    public function execute(Bot $bot, array $payload): WebhookResult
    {
        $message = $this->parser->parse($payload);
        $response = $this->builder->build($bot, $message);

        return new WebhookResult($response);
    }
}

// Usage
app(ProcessWebhookAction::class)->execute($bot, $payload);
```

## Replace Conditional with Strategy

### Before (Long Switch/If)
```php
class MessageHandler
{
    public function handle(Message $message): Response
    {
        switch ($message->type) {
            case 'text':
                // 30 lines
                break;
            case 'image':
                // 30 lines
                break;
            case 'sticker':
                // 30 lines
                break;
        }
    }
}
```

### After (Strategy Pattern)
```php
// Interface
interface MessageStrategy
{
    public function handle(Message $message): Response;
}

// Implementations
class TextMessageStrategy implements MessageStrategy
{
    public function handle(Message $message): Response
    {
        // Handle text
    }
}

class ImageMessageStrategy implements MessageStrategy
{
    public function handle(Message $message): Response
    {
        // Handle image
    }
}

// Handler
class MessageHandler
{
    private array $strategies = [
        'text' => TextMessageStrategy::class,
        'image' => ImageMessageStrategy::class,
        'sticker' => StickerMessageStrategy::class,
    ];

    public function handle(Message $message): Response
    {
        $strategy = app($this->strategies[$message->type]);
        return $strategy->handle($message);
    }
}
```

## Fix N+1 Query

### Before (N+1 Problem)
```php
$bots = Bot::all();

foreach ($bots as $bot) {
    echo $bot->user->name;           // N queries
    echo $bot->settings->language;   // N more queries
}
```

### After (Eager Loading)
```php
$bots = Bot::with(['user', 'settings'])->get();

foreach ($bots as $bot) {
    echo $bot->user->name;           // Already loaded
    echo $bot->settings->language;   // Already loaded
}
```

## Refactor Raw SQL to Eloquent

### Before (Raw SQL)
```php
$results = DB::select("
    SELECT b.*, COUNT(c.id) as conversation_count
    FROM bots b
    LEFT JOIN conversations c ON c.bot_id = b.id
    WHERE b.user_id = ?
    GROUP BY b.id
", [$userId]);
```

### After (Eloquent)
```php
$results = Bot::where('user_id', $userId)
    ->withCount('conversations')
    ->get();
```

## Checklist Before Refactoring

- [ ] Understand the existing code
- [ ] Have tests covering the code
- [ ] Run tests before starting
- [ ] Make small, incremental changes
- [ ] Run tests after each change
- [ ] Commit after each successful refactor
