<?php

namespace Tests\Unit\Services\Webhook;

use App\Models\Bot;
use App\Services\Webhook\WebhookContext;
use App\Services\Webhook\WebhookPipeline;
use Closure;
use Tests\TestCase;

class WebhookPipelineTest extends TestCase
{
    private function bot(): Bot
    {
        $bot = new Bot;
        $bot->id = 1;

        return $bot;
    }

    public function test_runs_steps_in_order(): void
    {
        $order = [];
        $pipeline = app(WebhookPipeline::class);
        $ctx = new WebhookContext($this->bot(), [], 'test');

        $pipeline->run($ctx, [
            function (WebhookContext $c, Closure $next) use (&$order) {
                $order[] = 'a';
                $next($c);
            },
            function (WebhookContext $c, Closure $next) use (&$order) {
                $order[] = 'b';
                $next($c);
            },
        ]);

        $this->assertSame(['a', 'b'], $order);
    }

    public function test_step_can_short_circuit(): void
    {
        $pipeline = app(WebhookPipeline::class);
        $ctx = new WebhookContext($this->bot(), [], 'test');
        $reached = false;

        $pipeline->run($ctx, [
            function (WebhookContext $c, Closure $next) { /* no $next call */
            },
            function (WebhookContext $c, Closure $next) use (&$reached) {
                $reached = true;
                $next($c);
            },
        ]);

        $this->assertFalse($reached);
    }

    public function test_runs_handle_objects_and_closures_in_order_and_supports_short_circuit(): void
    {
        $order = [];
        $objectStep = new class($order)
        {
            public function __construct(private array &$order) {}

            public function handle(WebhookContext $ctx, Closure $next): void
            {
                $this->order[] = 'object';
                $next($ctx);
            }
        };
        $closureStep = function (WebhookContext $ctx, Closure $next) use (&$order): void {
            $order[] = 'closure';
            // short-circuit: do not call $next
        };
        $neverStep = function (WebhookContext $ctx, Closure $next) use (&$order): void {
            $order[] = 'never';
        };

        (new WebhookPipeline)->run(new WebhookContext(new Bot, [], 'line'), [$objectStep, $closureStep, $neverStep]);

        $this->assertSame(['object', 'closure'], $order);
    }
}
