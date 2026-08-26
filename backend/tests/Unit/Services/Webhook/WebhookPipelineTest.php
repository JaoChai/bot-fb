<?php

namespace Tests\Unit\Services\Webhook;

use App\Models\Bot;
use App\Services\Webhook\WebhookContext;
use App\Services\Webhook\WebhookPipeline;
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
            function (WebhookContext $c, \Closure $next) use (&$order) {
                $order[] = 'a';
                $next($c);
            },
            function (WebhookContext $c, \Closure $next) use (&$order) {
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
            function (WebhookContext $c, \Closure $next) { /* no $next call */ },
            function (WebhookContext $c, \Closure $next) use (&$reached) {
                $reached = true;
                $next($c);
            },
        ]);

        $this->assertFalse($reached);
    }
}
