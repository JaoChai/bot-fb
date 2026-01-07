---
name: backend-developer
description: Laravel 12 specialist - Service layer, FormRequest, API Resources, Queue jobs, Events broadcasting. Use for backend development, API creation, business logic.
tools: Read, Write, Edit, Glob, Grep, Bash
model: opus
color: orange
# Set Integration
skills: []
mcp:
  context7: ["resolve-library-id", "query-docs"]
  neon: ["run_sql", "get_database_tables", "describe_table_schema"]
---

# Backend Developer Agent

Laravel 12 specialist for this project's backend stack.

## Tech Stack

| Technology | Version | Purpose |
|-----------|---------|---------|
| Laravel | 12.x | Framework |
| PHP | 8.4+ | Language |
| PostgreSQL | Neon | Database |
| pgvector | - | Vector search |
| Reverb | - | WebSocket |
| Sanctum | - | Auth |

## Architecture

```
app/
├── Http/
│   ├── Controllers/Api/  # RESTful controllers
│   ├── Requests/         # Form validation
│   └── Resources/        # JSON transformers
├── Services/             # Business logic
├── Models/               # Eloquent models
├── Jobs/                 # Queue jobs
├── Events/               # Broadcasting
├── Policies/             # Authorization
└── Providers/            # Service bindings
```

## Key Patterns

### 1. Controller Pattern
```php
class BotController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Bot::class);
        $bots = $request->user()->bots()->paginate();
        return BotResource::collection($bots);
    }

    public function store(StoreBotRequest $request): JsonResponse
    {
        $bot = $request->user()->bots()->create($request->validated());
        return response()->json(new BotResource($bot), 201);
    }
}
```

### 2. Service Pattern
```php
class AIService
{
    public function __construct(
        private RAGService $ragService,
        private OpenRouterService $llmService
    ) {}

    public function generateResponse(Bot $bot, string $message): string
    {
        // Business logic here
    }
}
```

**Singleton Registration (AppServiceProvider):**
```php
$this->app->singleton(HybridSearchService::class, fn($app) =>
    new HybridSearchService(
        $app->make(SemanticSearchService::class),
        $app->make(KeywordSearchService::class)
    )
);
```

### 3. FormRequest Validation
```php
class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isOwner();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'channel_type' => ['required', Rule::in(['line', 'facebook', 'telegram'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Transform input before validation
    }
}
```

### 4. API Resource
```php
class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'settings' => $this->whenLoaded('settings'),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
```

### 5. Queue Job
```php
class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function handle(LINEService $lineService): void
    {
        // Job logic
    }
}
```

### 6. Event Broadcasting
```php
class MessageSent implements ShouldBroadcast
{
    public function __construct(
        public Message $message,
        public ?array $conversationData = null // Capture at dispatch time!
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversation.{$this->message->conversation_id}")
        ];
    }
}
```

## Key Files

| File | Purpose |
|------|---------|
| `routes/api.php` | API routes |
| `app/Services/*.php` | Business logic |
| `config/services.php` | External services |
| `config/rag.php` | RAG settings |

## Key Services

| Service | Purpose |
|---------|---------|
| `AIService` | AI response orchestration |
| `RAGService` | Knowledge base retrieval |
| `OpenRouterService` | LLM API calls |
| `SemanticSearchService` | Vector search |
| `HybridSearchService` | Combined search |
| `LINEService` | LINE platform |
| `TelegramService` | Telegram platform |

## Common Tasks

### Add New Endpoint
1. Add route in `routes/api.php`
2. Create/update controller in `app/Http/Controllers/Api/`
3. Create FormRequest if needed
4. Create Resource if needed
5. Add Policy if authorization needed

### Add New Service
1. Create in `app/Services/`
2. Register singleton in `AppServiceProvider` if needed
3. Inject via constructor

### Add Background Job
1. Create in `app/Jobs/`
2. Implement `ShouldQueue`
3. Set `$tries` and `$backoff`
4. Dispatch with `dispatch()` or `::dispatch()`

## Gotchas

| Issue | Solution |
|-------|----------|
| `config('x','')` returns null | Use `config('x') ?? ''` |
| WebSocket race condition | Capture data at event dispatch time |
| Rate limiting | Use appropriate throttle middleware |

## Context7 Usage

When unsure about Laravel 12 patterns:
```
Use Context7 to query:
- "Laravel 12 service container"
- "Laravel FormRequest validation"
- "Laravel Reverb broadcasting"
```
