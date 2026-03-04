---
name: backend-dev
description: |
  Laravel 12 specialist for API development. Handles controllers, services, FormRequests, API Resources, jobs, events, broadcasting.
  Triggers: 'API', 'endpoint', 'controller', 'service', 'Laravel', 'backend', 'server'.
  Use when: creating/modifying API endpoints, business logic, backend services, fixing server-side issues.
allowed-tools:
  - Bash(php artisan*)
  - Read
  - Grep
  - Edit
context:
  - path: routes/api.php
  - path: app/Http/Controllers/
  - path: app/Services/
---

# Backend Development

Laravel 12 + PHP 8.4 specialist for BotFacebook API.

## Quick Start

```php
// Controller pattern
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
```

## MCP Tools Available

- **context7**: `resolve-library-id`, `query-docs` - Get latest Laravel docs
- **neon**: `run_sql`, `describe_table_schema` - Database operations
- **sentry**: `search_issues`, `get_issue_details` - Error tracking
- **claude-mem**: `search`, `get_observations` - Search past implementations

## Memory Search (Before Starting)

**Always search memory first** to find past API patterns and service implementations.

### Recommended Searches

```
# Search for similar implementations
search(query="API endpoint", project="bot-fb", type="feature", limit=5)

# Find service patterns
search(query="service implementation", project="bot-fb", concepts=["pattern"], limit=5)
```

### Search by Scenario

| Scenario | Search Query |
|----------|--------------|
| Creating new endpoint | `search(query="controller resource", project="bot-fb", concepts=["pattern"], limit=5)` |
| Adding validation | `search(query="FormRequest validation", project="bot-fb", type="feature", limit=5)` |
| Creating job | `search(query="queue job", project="bot-fb", concepts=["pattern"], limit=5)` |

## Architecture

```
app/
├── Http/
│   ├── Controllers/     # Thin controllers, delegate to services
│   ├── Requests/        # FormRequest validation
│   └── Resources/       # API response transformation
├── Services/            # Business logic (80%+ test coverage)
├── Models/              # Eloquent models with relationships
├── Jobs/                # Queue jobs for async tasks
├── Events/              # Event classes
├── Listeners/           # Event handlers
└── Policies/            # Authorization logic
```

### Flow-Centric Architecture

**Flow is the central configuration entity, not Bot.**

Bot still exists as the top-level entity (owns platform credentials, user association),
but Flow holds the active runtime configuration:

| Concern | Owned by Flow (not Bot) |
|---------|------------------------|
| System prompt | `flow.system_prompt` |
| LLM parameters | `flow.temperature`, `flow.max_tokens` |
| Agentic mode | `flow.agentic_mode`, `flow.enabled_tools` |
| Knowledge bases | `flow.knowledgeBases()` (many-to-many pivot) |
| Agent safety | `flow.agent_timeout_seconds`, `flow.hitl_enabled` |
| Second AI | `flow.second_ai_enabled`, `flow.second_ai_options` |

```
Bot (1) ──→ (N) Flow ──→ (M) KnowledgeBase   (pivot: kb_top_k, kb_similarity_threshold)
              │
              └──→ (N) FlowPlugin
```

A Bot has one **default flow** (`bot.default_flow_id`). When resolving config, always
prefer Flow values with Bot as fallback:

```php
$resolvedFlow = $flow ?? $this->flowCacheService->getDefaultFlow($bot->id);
$maxTokens = $resolvedFlow?->max_tokens ?? $bot->llm_max_tokens;
$temperature = $resolvedFlow?->temperature ?? $bot->llm_temperature;
```

### FlowCacheService

`FlowCacheService` caches Flow lookups to reduce database load. It is injected via
constructor into services that need frequent Flow access (e.g., `RAGService`).

```php
// Constructor injection pattern (RAGService)
public function __construct(
    protected SemanticSearchService $semanticSearchService,
    protected HybridSearchService $hybridSearchService,
    protected OpenRouterService $openRouter,
    protected IntentAnalysisService $intentAnalysis,
    protected FlowCacheService $flowCacheService,       // 5th parameter
    protected ?QueryEnhancementService $queryEnhancement = null,
    // ...optional params after
) {}
```

Key methods:
- `getDefaultFlow(int $botId): ?Flow` — cached lookup (30-min TTL)
- `hasFlows(int $botId): bool` — cached existence check
- `invalidateBot(int $botId): void` — call when flows are created/updated/deleted
- `invalidateDefaultFlow(int $botId): void` — call when default flow changes

**Important**: When adding `FlowCacheService` to a new service constructor, place it
before optional (`?Type`) parameters. Update corresponding test files to match
the constructor signature.

### KB Detection (Flow-Level, Not Bot-Level)

Knowledge base availability is determined by **Flow KB attachment**, not `bot.kb_enabled`.

```php
// CORRECT — RAGService pattern (source of truth)
protected function shouldUseKnowledgeBase(Bot $bot): bool
{
    $defaultFlow = $this->flowCacheService->getDefaultFlow($bot->id);
    return $defaultFlow && $defaultFlow->knowledgeBases()->exists();
}
```

The old `bot.kb_enabled` flag is still referenced in `IntentAnalysisService` for
backward compatibility, but RAGService intentionally ignores it — if the default
flow has KBs attached, they will be used regardless of `bot.kb_enabled`.

When writing new code that needs to check KB availability, follow the RAGService
pattern (Flow attachment) rather than checking `bot.kb_enabled`.

## Key Patterns

### Service Layer Pattern

```php
class BotService
{
    public function create(array $data): Bot
    {
        return DB::transaction(function () use ($data) {
            $bot = Bot::create($data);
            // Additional logic...
            return $bot;
        });
    }
}
```

### FormRequest Validation

```php
class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Or policy check
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['line', 'telegram'])],
        ];
    }
}
```

### API Resource

```php
class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
```

## API Standards

### Response Format
```json
{
  "data": { },
  "meta": { "timestamp": "..." },
  "errors": []
}
```

### Status Codes
```
200 OK | 201 Created | 400 Bad Request
401 Unauthorized | 404 Not Found | 422 Validation
429 Rate Limit | 500 Server Error
```

### RESTful Routes
```
GET    /api/v1/bots
POST   /api/v1/bots
GET    /api/v1/bots/{id}
PUT    /api/v1/bots/{id}
DELETE /api/v1/bots/{id}
```

## Detailed Guides

- **Laravel Patterns**: See [LARAVEL_PATTERNS.md](LARAVEL_PATTERNS.md)
- **API Standards**: See [API_STANDARDS.md](API_STANDARDS.md)

## Key Files

| File | Purpose |
|------|---------|
| `routes/api.php` | API route definitions |
| `app/Providers/AppServiceProvider.php` | Service registration |
| `config/*.php` | Configuration files |

## Common Tasks

### Create New Endpoint
1. Create FormRequest in `app/Http/Requests/`
2. Create/update Service in `app/Services/`
3. Create Resource in `app/Http/Resources/`
4. Add route in `routes/api.php`
5. Create Controller method
6. Write tests

### Add New Job
1. Create job: `php artisan make:job ProcessMessage`
2. Implement `handle()` method
3. Dispatch: `ProcessMessage::dispatch($data)`

### Add New Event
1. Create event: `php artisan make:event MessageReceived`
2. Create listener: `php artisan make:listener HandleMessage`
3. Register in `EventServiceProvider`

## Critical Gotchas

| Problem | Solution |
|---------|----------|
| `config('x','')` returns null | Use `config('x') ?? ''` |
| N+1 queries | Use eager loading: `with(['relation'])` |
| Race condition | Use DB locks: `lockForUpdate()` |

## Testing Commands

```bash
php artisan test                    # All tests
php artisan test --filter Unit      # Unit only
php artisan test --filter Feature   # Feature only
```
