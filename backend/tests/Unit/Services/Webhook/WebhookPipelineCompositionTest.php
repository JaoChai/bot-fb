<?php

namespace Tests\Unit\Services\Webhook;

use App\Services\AIService;
use App\Services\Channel\ChannelAdapterFactory;
use App\Services\FacebookService;
use App\Services\FlowPluginService;
use App\Services\LeadRecoveryService;
use App\Services\TelegramService;
use App\Services\Webhook\Steps\BroadcastStep;
use App\Services\Webhook\Steps\DedupUserMessageStep;
use App\Services\Webhook\Steps\Facebook\FacebookTypingStep;
use App\Services\Webhook\Steps\FlowPluginStep;
use App\Services\Webhook\Steps\GenerateResponseStep;
use App\Services\Webhook\Steps\PersistUserMessageStep;
use App\Services\Webhook\Steps\ResolveConversationStep;
use App\Services\Webhook\Steps\SendResponseStep;
use App\Services\Webhook\Steps\Telegram\TelegramMediaStep;
use App\Services\Webhook\WebhookPipeline;
use Closure;
use Mockery;
use ReflectionFunction;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Pins the ordered step lists produced by WebhookPipeline::facebook()/::telegram()
 * so a future refactor can't silently drop or reorder a step (review #255 finding #3).
 */
class WebhookPipelineCompositionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindIfUnresolvable(ChannelAdapterFactory::class);
        $this->bindIfUnresolvable(LeadRecoveryService::class);
        $this->bindIfUnresolvable(FlowPluginService::class);
    }

    private function bindIfUnresolvable(string $class): void
    {
        try {
            app($class);
        } catch (\Throwable) {
            $this->app->instance($class, Mockery::mock($class));
        }
    }

    private function classNames(array $steps): array
    {
        return array_map(fn ($step) => $step instanceof Closure ? Closure::class : $step::class, $steps);
    }

    public function test_facebook_step_order(): void
    {
        $steps = WebhookPipeline::facebook(Mockery::mock(AIService::class), Mockery::mock(FacebookService::class));

        $this->assertSame([
            Closure::class,
            FacebookTypingStep::class,
            GenerateResponseStep::class,
            SendResponseStep::class,
            FacebookTypingStep::class,
            BroadcastStep::class,
        ], $this->classNames($steps));

        $typingOnAction = (new ReflectionProperty(FacebookTypingStep::class, 'action'))->getValue($steps[1]);
        $typingOffAction = (new ReflectionProperty(FacebookTypingStep::class, 'action'))->getValue($steps[4]);

        $this->assertSame('typing_on', $typingOnAction);
        $this->assertSame('typing_off', $typingOffAction);
    }

    public function test_telegram_step_order(): void
    {
        $steps = WebhookPipeline::telegram(Mockery::mock(TelegramService::class), Mockery::mock(AIService::class));

        $this->assertSame([
            Closure::class,
            GenerateResponseStep::class,
            SendResponseStep::class,
            FlowPluginStep::class,
            BroadcastStep::class,
        ], $this->classNames($steps));
    }

    public function test_transactional_inner_steps_include_dedup_before_persist(): void
    {
        $facebookSteps = WebhookPipeline::facebook(Mockery::mock(AIService::class), Mockery::mock(FacebookService::class));
        $telegramSteps = WebhookPipeline::telegram(Mockery::mock(TelegramService::class), Mockery::mock(AIService::class));

        $facebookInner = (new ReflectionFunction($facebookSteps[0]))->getStaticVariables()['innerSteps'];
        $telegramInner = (new ReflectionFunction($telegramSteps[0]))->getStaticVariables()['innerSteps'];

        $this->assertSame([
            ResolveConversationStep::class,
            DedupUserMessageStep::class,
            PersistUserMessageStep::class,
        ], $this->classNames($facebookInner));

        $this->assertSame([
            ResolveConversationStep::class,
            DedupUserMessageStep::class,
            TelegramMediaStep::class,
            PersistUserMessageStep::class,
        ], $this->classNames($telegramInner));
    }
}
