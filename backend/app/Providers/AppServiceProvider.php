<?php

namespace App\Providers;

use App\Models\Bot;
use App\Models\Document;
use App\Models\KnowledgeBase;
use App\Models\QuickReply;
use App\Policies\BotPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\KnowledgeBasePolicy;
use App\Policies\QuickReplyPolicy;
use App\Services\Agent\AgentLoopService;
use App\Services\CostTrackingService;
use App\Services\FlowCacheService;
use App\Services\HybridSearchService;
use App\Services\IntentAnalysisService;
use App\Services\JinaRerankerService;
use App\Services\KeywordSearchService;
use App\Services\OpenRouterService;
use App\Services\QueryEnhancementService;
use App\Services\RAGService;
use App\Services\SemanticCacheService;
use App\Services\SemanticSearchService;
use App\Services\SecondAI\SecondAIService;
use App\Services\SecondAI\FactCheckService;
use App\Services\SecondAI\PolicyCheckService;
use App\Services\SecondAI\PersonalityCheckService;
use App\Services\SecondAI\UnifiedCheckService;
use App\Services\SecondAI\PromptInjectionDetector;
use App\Services\SecondAI\SecondAIMetricsService;
use App\Services\ModelCapabilityService;
use App\Services\CircuitBreakerService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register custom PostgreSQL connection for proper boolean handling
        Connection::resolverFor('pgsql', function ($connection, $database, $prefix, $config) {
            return new \App\Database\PostgresConnection($connection, $database, $prefix, $config);
        });

        // Register HybridSearchService with JinaRerankerService dependency
        $this->app->singleton(HybridSearchService::class, function ($app) {
            return new HybridSearchService(
                $app->make(SemanticSearchService::class),
                $app->make(KeywordSearchService::class),
                $app->make(JinaRerankerService::class)
            );
        });

        // Register RAGService with all dependencies
        $this->app->singleton(RAGService::class, function ($app) {
            return new RAGService(
                $app->make(SemanticSearchService::class),
                $app->make(HybridSearchService::class),
                $app->make(OpenRouterService::class),
                $app->make(IntentAnalysisService::class),
                $app->make(FlowCacheService::class),
                $app->make(QueryEnhancementService::class),
                $app->make(SemanticCacheService::class)
            );
        });

        // Register CostTrackingService as scoped (request-bound) to prevent
        // concurrent requests from corrupting each other's cost tracking state
        $this->app->scoped(CostTrackingService::class, function ($app) {
            return new CostTrackingService();
        });

        // Register AgentLoopService as scoped (depends on scoped CostTrackingService)
        $this->app->scoped(AgentLoopService::class);

        // Register PromptInjectionDetector
        $this->app->singleton(PromptInjectionDetector::class, function ($app) {
            return new PromptInjectionDetector();
        });

        // Register SecondAIMetricsService
        $this->app->singleton(SecondAIMetricsService::class, function ($app) {
            return new SecondAIMetricsService();
        });

        // Register SecondAIService with all check services
        $this->app->singleton(SecondAIService::class, function ($app) {
            return new SecondAIService(
                $app->make(FactCheckService::class),
                $app->make(PolicyCheckService::class),
                $app->make(PersonalityCheckService::class),
                $app->make(UnifiedCheckService::class),
                $app->make(PromptInjectionDetector::class),
                $app->make(SecondAIMetricsService::class)
            );
        });

        // Register individual check services
        $this->app->singleton(FactCheckService::class, function ($app) {
            return new FactCheckService(
                $app->make(HybridSearchService::class),
                $app->make(OpenRouterService::class)
            );
        });

        $this->app->singleton(PolicyCheckService::class, function ($app) {
            return new PolicyCheckService(
                $app->make(OpenRouterService::class)
            );
        });

        $this->app->singleton(PersonalityCheckService::class, function ($app) {
            return new PersonalityCheckService(
                $app->make(OpenRouterService::class)
            );
        });

        $this->app->singleton(UnifiedCheckService::class, function ($app) {
            return new UnifiedCheckService(
                $app->make(OpenRouterService::class),
                $app->make(RAGService::class)
            );
        });

        // Register ModelCapabilityService for dynamic model capability detection
        $this->app->singleton(ModelCapabilityService::class, function ($app) {
            return new ModelCapabilityService(
                $app->make(CircuitBreakerService::class)
            );
        });

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Bot::class, BotPolicy::class);
        Gate::policy(KnowledgeBase::class, KnowledgeBasePolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(QuickReply::class, QuickReplyPolicy::class);

        $this->configureRateLimiting();
        $this->configureQueryLogging();
    }

    /**
     * Configure slow query logging for performance monitoring.
     * Only enabled in local/development environment.
     */
    protected function configureQueryLogging(): void
    {
        // Only log slow queries in local environment
        if (! app()->environment('local')) {
            return;
        }

        $slowQueryThreshold = (int) config('app.slow_query_threshold', 100); // ms

        DB::listen(function ($query) use ($slowQueryThreshold) {
            if ($query->time >= $slowQueryThreshold) {
                Log::channel('single')->warning('Slow query detected', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => $query->time,
                    'connection' => $query->connectionName,
                ]);
            }
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Default API rate limit: 300 requests per minute (increased from 60)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by(
                $request->user()?->id ?: $request->ip()
            )->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Too many requests. Please try again later.',
                    'retry_after' => $headers['Retry-After'] ?? 60,
                ], 429, $headers);
            });
        });

        // Strict rate limit for authentication: 5 attempts per minute
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by(
                $request->input('email') . '|' . $request->ip()
            )->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Too many login attempts. Please try again later.',
                    'retry_after' => $headers['Retry-After'] ?? 60,
                ], 429, $headers);
            });
        });

        // Webhook rate limit: 1000 requests per minute per IP
        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(1000)->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'message' => 'Webhook rate limit exceeded.',
                        'retry_after' => $headers['Retry-After'] ?? 60,
                    ], 429, $headers);
                });
        });

        // Bot test endpoint: 10 requests per minute
        RateLimiter::for('bot-test', function (Request $request) {
            return Limit::perMinute(10)->by(
                $request->user()?->id ?: $request->ip()
            )->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Too many test requests. Please wait before testing again.',
                    'retry_after' => $headers['Retry-After'] ?? 60,
                ], 429, $headers);
            });
        });

        // Upload rate limit: 20 uploads per hour
        RateLimiter::for('uploads', function (Request $request) {
            return Limit::perHour(20)->by(
                $request->user()?->id ?: $request->ip()
            )->response(function (Request $request, array $headers) {
                return response()->json([
                    'message' => 'Upload limit reached. Please try again later.',
                    'retry_after' => $headers['Retry-After'] ?? 3600,
                ], 429, $headers);
            });
        });

    }
}
