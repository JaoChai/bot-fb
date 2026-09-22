<?php

namespace Tests\Unit\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\LINEService;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AggregatedMessageDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function models(string $content = 'Hello'): array
    {
        $bot = Bot::factory()->make(['id' => 26, 'status' => 'active', 'channel_type' => 'line']);
        $conversation = Conversation::factory()->make([
            'id' => 100,
            'bot_id' => 26,
            'external_customer_id' => 'U_test',
            'channel_type' => 'line',
        ]);
        $message = Message::factory()->fromBot()->make([
            'id' => 200,
            'conversation_id' => 100,
            'content' => $content,
        ]);

        return [$bot, $conversation, $message];
    }

    public function test_flex_response_uses_one_line_push_and_skips_bubbles(): void
    {
        [$bot, $conversation, $message] = $this->models('Pay now');
        $flex = ['type' => 'flex', 'altText' => 'Payment'];

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk-flex');
        $line->shouldReceive('push')->once()->with($bot, 'U_test', [$flex], 'rk-flex');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldNotReceive('isEnabled');

        $payment = Mockery::mock(PaymentFlexService::class);
        $payment->shouldReceive('tryConvertToFlex')->once()->with('Pay now', $conversation)->andReturn($flex);

        (new AggregatedMessageDeliveryService($line, $bubbles, $payment))
            ->deliver($bot, $conversation, $message, 'U_test');

        $this->addToAssertionCount(1);
    }

    public function test_bubble_response_uses_bubble_service_and_skips_plain_push(): void
    {
        [$bot, $conversation, $message] = $this->models('Bubble reply');
        $parsed = [['type' => 'bubble', 'content' => 'Bubble reply']];

        $line = Mockery::mock(LINEService::class);
        $line->shouldNotReceive('push');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->once()->with($bot)->andReturn(true);
        $bubbles->shouldReceive('parseIntoBubbles')->once()->with('Bubble reply', $bot)->andReturn($parsed);
        $bubbles->shouldReceive('sendBubbles')->once()->with($bot, 'U_test', null, $parsed, $conversation);

        $payment = Mockery::mock(PaymentFlexService::class);
        $payment->shouldReceive('tryConvertToFlex')->once()->with('Bubble reply', $conversation)->andReturn('Bubble reply');

        (new AggregatedMessageDeliveryService($line, $bubbles, $payment))
            ->deliver($bot, $conversation, $message, 'U_test');

        $this->addToAssertionCount(1);
    }

    public function test_plain_response_generates_retry_key_and_pushes_text(): void
    {
        [$bot, $conversation, $message] = $this->models('Plain reply');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk-text');
        $line->shouldReceive('push')->once()->with($bot, 'U_test', ['Plain reply'], 'rk-text');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->once()->with($bot)->andReturn(false);

        $payment = Mockery::mock(PaymentFlexService::class);
        $payment->shouldReceive('tryConvertToFlex')->once()->with('Plain reply', $conversation)->andReturn('Plain reply');

        (new AggregatedMessageDeliveryService($line, $bubbles, $payment))
            ->deliver($bot, $conversation, $message, 'U_test');

        $this->addToAssertionCount(1);
    }

    public function test_delivery_exception_is_not_swallowed(): void
    {
        [$bot, $conversation, $message] = $this->models('Plain reply');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk-text');
        $line->shouldReceive('push')->once()->andThrow(new \RuntimeException('LINE unavailable'));

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->once()->andReturn(false);

        $payment = Mockery::mock(PaymentFlexService::class);
        $payment->shouldReceive('tryConvertToFlex')->once()->andReturn('Plain reply');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LINE unavailable');

        (new AggregatedMessageDeliveryService($line, $bubbles, $payment))
            ->deliver($bot, $conversation, $message, 'U_test');
    }
}
