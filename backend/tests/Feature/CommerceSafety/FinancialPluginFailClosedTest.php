<?php

namespace Tests\Feature\CommerceSafety;

use App\Jobs\ReserveAccountStock;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\FlowPluginService;
use App\Services\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FinancialPluginFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    private Message $botMessage;

    private FlowPlugin $financial;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ]),
        ]);
        Queue::fake();
        config(['services.openrouter.api_key' => 'test-key']);

        $owner = User::factory()->owner()->create();
        $this->bot = Bot::factory()->active()->create([
            'user_id' => $owner->id,
            'primary_chat_model' => 'test-model',
        ]);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'channel_type' => 'line',
        ]);
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $this->bot->update(['default_flow_id' => $flow->id]);

        $this->financial = $this->plugin($flow, 'financial');
        $this->plugin($flow, 'support');
        $this->botMessage = $this->conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'ลูกค้าสอบถามครับ',
        ]);
    }

    public static function scopeMatrix(): array
    {
        return [
            'unknown mode and malformed string id' => ['unexpected', true, 0, [], 0, 'hold'],
            'missing mode and malformed string id' => [null, true, 0, [], 0, 'hold'],
            'unknown mode and real integer id' => ['unexpected', false, 1, ['support'], 0, 'hold'],
            'missing mode and real integer id' => [null, false, 1, ['support'], 0, 'hold'],
            'valid off' => ['off', false, 2, ['financial', 'support'], 1, 'off'],
            'valid shadow' => ['shadow', false, 2, ['financial', 'support'], 1, 'shadow'],
            'valid enforce' => ['enforce', false, 1, ['support'], 0, 'enforce'],
            'valid hold' => ['hold', false, 1, ['support'], 0, 'hold'],
        ];
    }

    #[DataProvider('scopeMatrix')]
    public function test_plugin_execution_uses_effective_trusted_financial_scope(
        ?string $rawMode,
        bool $malformedIds,
        int $expectedEvaluations,
        array $expectedChats,
        int $expectedOrders,
        string $expectedMode,
    ): void {
        $scope = [
            'payment_plugin_ids' => [
                $malformedIds ? (string) $this->financial->id : $this->financial->id,
            ],
        ];
        if ($rawMode !== null) {
            $scope['mode'] = $rawMode;
        }
        config(["commerce_safety.bots.{$this->bot->id}" => $scope]);

        $openRouter = $this->mock(OpenRouterService::class);
        $openRouter->shouldReceive('chat')
            ->times($expectedEvaluations)
            ->andReturn([
                'content' => '{"triggered":true,"variables":{"amount":"199","product":"Page"}}',
            ]);

        $this->assertSame($expectedMode, app(SafetyScope::class)->mode($this->bot));

        app(FlowPluginService::class)->executePlugins(
            $this->bot,
            $this->conversation,
            $this->botMessage,
        );

        $this->assertSame($expectedOrders, Order::count());
        Queue::assertNotPushed(ReserveAccountStock::class);
        Http::assertSentCount(count($expectedChats));

        foreach (['financial', 'support'] as $chatId) {
            $assertion = in_array($chatId, $expectedChats, true) ? 'assertSent' : 'assertNotSent';
            Http::$assertion(fn ($request) => $request['chat_id'] === $chatId
                && $request['text'] === "199 Page\n<b>📦 รายการสินค้า</b>\n• Page ×1");
        }
    }

    private function plugin(Flow $flow, string $chatId): FlowPlugin
    {
        return FlowPlugin::create([
            'flow_id' => $flow->id,
            'name' => $chatId,
            'type' => 'telegram',
            'enabled' => true,
            'trigger_condition' => 'always',
            // Deliberately no trigger_keywords: every evaluated plugin reaches OpenRouter.
            'config' => [
                'access_token' => 'test-token',
                'chat_id' => $chatId,
                'message_template' => '{amount} {product}',
            ],
        ]);
    }
}
