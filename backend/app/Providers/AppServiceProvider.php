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
use App\Services\HybridSearchService;
use App\Services\JinaRerankerService;
use App\Services\KeywordSearchService;
use App\Services\OpenRouterService;
use App\Services\QueryEnhancementService;
use App\Services\RAGService;
use App\Services\SemanticSearchService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
                $app->make(QueryEnhancementService::class)
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
