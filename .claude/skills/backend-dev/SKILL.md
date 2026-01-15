---
name: backend-dev
description: Laravel 12 specialist for API development. Handles controllers, services, FormRequests, API Resources, jobs, events, broadcasting. Use when creating/modifying API endpoints, business logic, backend services, or fixing server-side issues. Includes RESTful API design and database integration patterns.
---

# Backend Development

Laravel 12 + PHP 8.2 specialist for BotFacebook API.

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
