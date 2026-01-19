---
id: laravel-002-service-provider
title: Service Provider Registration
impact: MEDIUM
impactDescription: "Ensures proper dependency injection and service configuration"
category: laravel
tags: [service-provider, dependency-injection, container]
relatedRules: [laravel-003-service-layer]
---

## Why This Matters

Service providers are the central place for bootstrapping Laravel applications. They register bindings, configure services, and set up event listeners. Proper use ensures clean dependency injection and testable code.

## Bad Example

```php
// Problem: Creating dependencies directly
class BotController extends Controller
{
    public function store(Request $request)
    {
        $service = new BotService(new NotificationService()); // Hard coupling
        return $service->create($request->all());
    }
}
```

**Why it's wrong:**
- Dependencies hard-coded
- Can't mock for testing
- No singleton management
- Configuration scattered

## Good Example

```php
// app/Providers/AppServiceProvider.php
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind interface to implementation
        $this->app->bind(
            NotificationServiceInterface::class,
            NotificationService::class
        );

        // Singleton for expensive services
        $this->app->singleton(OpenRouterService::class, function ($app) {
            return new OpenRouterService(
                config('services.openrouter.api_key'),
                config('services.openrouter.base_url')
            );
        });
    }

    public function boot(): void
    {
        // Configuration after all providers registered
        Model::preventLazyLoading(!app()->isProduction());
    }
}

// Controller with injected dependencies
class BotController extends Controller
{
    public function __construct(
        private BotService $botService // Auto-resolved
    ) {}
}
```

**Why it's better:**
- Dependencies injected
- Easy to mock in tests
- Singleton for expensive objects
- Configuration centralized

## Project-Specific Notes

**BotFacebook Service Providers:**
- `AppServiceProvider` - General bindings
- `EventServiceProvider` - Event/listener mapping
- `AuthServiceProvider` - Policies registration

**Common Patterns:**
```php
// Contextual binding
$this->app->when(LINEService::class)
    ->needs(HttpClient::class)
    ->give(function () {
        return new HttpClient(['timeout' => 10]);
    });
```

## References

- [Laravel Service Providers](https://laravel.com/docs/providers)
- [Service Container](https://laravel.com/docs/container)
