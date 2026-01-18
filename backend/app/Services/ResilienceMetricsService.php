<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Sentry\Breadcrumb;
use Sentry\State\HubInterface;

class ResilienceMetricsService
{
    public function __construct(
        protected ?HubInterface $sentry = null
    ) {}

    /**
     * Record a circuit breaker state change.
     */
    public function recordCircuitStateChange(string $service, string $from, string $to): void
    {
        $message = "Circuit breaker for '{$service}' changed from {$from} to {$to}";

        // Log to application logs
        Log::info($message, [
            'service' => $service,
            'from_state' => $from,
            'to_state' => $to,
            'timestamp' => now()->toIso8601String(),
        ]);

        // Add Sentry breadcrumb for context in error reports
        $this->addBreadcrumb(
            category: 'circuit_breaker',
            message: $message,
            metadata: [
                'service' => $service,
                'from_state' => $from,
                'to_state' => $to,
            ],
            level: $to === 'open' ? Breadcrumb::LEVEL_WARNING : Breadcrumb::LEVEL_INFO
        );

        // If circuit opens, capture a Sentry message for alerting
        if ($to === 'open') {
            $this->captureMessage(
                "Circuit breaker opened for service: {$service}",
                'warning',
                [
                    'service' => $service,
                    'from_state' => $from,
                    'to_state' => $to,
                ]
            );
        }
    }

    /**
     * Record when a fallback was used due to circuit being open.
     */
    public function recordFallbackUsed(string $service, string $context): void
    {
        Log::info("Fallback used for service: {$service}", [
            'service' => $service,
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->addBreadcrumb(
            category: 'circuit_breaker',
            message: "Fallback used for {$service}",
            metadata: [
                'service' => $service,
                'context' => $context,
            ],
            level: Breadcrumb::LEVEL_WARNING
        );
    }

    /**
     * Record a successful operation that may help close the circuit.
     */
    public function recordSuccess(string $service, string $context): void
    {
        $this->addBreadcrumb(
            category: 'circuit_breaker',
            message: "Successful operation on {$service}",
            metadata: [
                'service' => $service,
                'context' => $context,
            ],
            level: Breadcrumb::LEVEL_INFO
        );
    }

    /**
     * Record a failure that may contribute to opening the circuit.
     */
    public function recordFailure(string $service, string $context, ?string $error = null): void
    {
        Log::warning("Operation failed for service: {$service}", [
            'service' => $service,
            'context' => $context,
            'error' => $error,
            'timestamp' => now()->toIso8601String(),
        ]);

        $this->addBreadcrumb(
            category: 'circuit_breaker',
            message: "Operation failed on {$service}",
            metadata: array_filter([
                'service' => $service,
                'context' => $context,
                'error' => $error,
            ]),
            level: Breadcrumb::LEVEL_ERROR
        );
    }

    /**
     * Add a Sentry breadcrumb.
     */
    protected function addBreadcrumb(
        string $category,
        string $message,
        array $metadata = [],
        string $level = Breadcrumb::LEVEL_INFO
    ): void {
        if (! $this->isSentryEnabled()) {
            return;
        }

        try {
            \Sentry\addBreadcrumb(
                new Breadcrumb(
                    level: $level,
                    type: 'default',
                    category: $category,
                    message: $message,
                    metadata: $metadata,
                    timestamp: time()
                )
            );
        } catch (\Throwable $e) {
            // Silently ignore Sentry failures
        }
    }

    /**
     * Capture a message to Sentry for alerting.
     */
    protected function captureMessage(string $message, string $level, array $context = []): void
    {
        if (! $this->isSentryEnabled()) {
            return;
        }

        try {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($message, $level, $context): void {
                $scope->setLevel(\Sentry\Severity::fromError($level === 'error' ? E_ERROR : E_WARNING));
                $scope->setTags(['type' => 'circuit_breaker']);

                foreach ($context as $key => $value) {
                    $scope->setContext('circuit_breaker', $context);
                    break;
                }

                \Sentry\captureMessage($message);
            });
        } catch (\Throwable $e) {
            // Silently ignore Sentry failures
        }
    }

    /**
     * Check if Sentry is enabled.
     */
    protected function isSentryEnabled(): bool
    {
        return ! empty(config('sentry.dsn'));
    }
}
