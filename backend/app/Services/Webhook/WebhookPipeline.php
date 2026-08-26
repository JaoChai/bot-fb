<?php

namespace App\Services\Webhook;

use Closure;

/**
 * Channel-agnostic webhook pipeline orchestrator (Task 9).
 *
 * Runs an array of steps as an onion: each step receives
 * (WebhookContext $ctx, Closure $next). A step calls $next($ctx) to
 * continue the chain; if it does not, the remaining steps are skipped
 * (short-circuit). The pipeline itself performs no work — the core at
 * the center of the onion is a no-op.
 */
class WebhookPipeline
{
    /**
     * @param array<int, callable> $steps Each step: fn(WebhookContext, Closure $next): void
     */
    public function run(WebhookContext $ctx, array $steps): void
    {
        $core = function (WebhookContext $c): void {};
        $chain = array_reduce(
            array_reverse($steps),
            fn (Closure $next, callable $step) => fn (WebhookContext $c) => $step($c, $next),
            $core
        );
        $chain($ctx);
    }
}
