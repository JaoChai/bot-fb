<?php

namespace Tests\Feature\Payment;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use App\Services\FlowPluginService;
use App\Services\OpenRouterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The plugin evaluator's "triggered" answer is a JSON boolean. A string answer
 * ("false") must never be treated as a trigger — before the fix, PHP truthiness
 * made the string "false" record a completed order.
 */
class PluginTriggerStrictBooleanTest extends TestCase
{
    use RefreshDatabase;

    /**
     * sqlite จาก migration เดิมจำกัด channel_type = line|facebook|demo แต่ prod (pgsql)
     * เพิ่ม telegram แล้ว — mirror ให้ sqlite ยอมรับเหมือน prod ก่อน seed fixture
     */
    private function mirrorTelegramChannelType(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        foreach (['customer_profiles', 'conversations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('channel_type', 20)->change();
            });
        }
    }

    /**
     * @return array{0: Bot, 1: Conversation, 2: Message}
     */
    private function seedPluginFixture(): array
    {
        $this->mirrorTelegramChannelType();
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $owner->id, 'channel_type' => 'telegram', 'utility_model' => 'dummy/model']);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create(['flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'alert', 'enabled' => true,
            'trigger_condition' => 'customer confirmed payment',
            'config' => ['access_token' => 'TOK', 'chat_id' => '999', 'message_template' => 'ยอด {amount}']]);
        $profile = CustomerProfile::factory()->create(['channel_type' => 'telegram', 'display_name' => 'Cust']);
        $conv = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'telegram', 'customer_profile_id' => $profile->id]);
        $conv->messages()->create(['sender' => 'user', 'content' => 'โอนแล้วนะ 5000', 'type' => 'text']);
        $botMsg = $conv->messages()->create(['sender' => 'bot', 'content' => 'รับทราบค่ะ', 'type' => 'text']);

        return [$bot->fresh(), $conv->fresh(), $botMsg];
    }

    private function fakeEvaluator(string $json): void
    {
        config(['services.openrouter.api_key' => 'dummy-key', 'rag.vip.enabled' => false]);
        $openRouter = \Mockery::mock(OpenRouterService::class);
        $openRouter->shouldReceive('chat')->andReturn(['content' => $json]);
        $this->app->instance(OpenRouterService::class, $openRouter);
    }

    public function test_evaluator_false_string_does_not_create_completed_order(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        [$bot, $conv, $botMsg] = $this->seedPluginFixture();

        // Control: a proper boolean false triggers nothing.
        $this->fakeEvaluator('{"triggered": false, "variables": {"amount": "5000"}}');
        app(FlowPluginService::class)->executePlugins($bot, $conv, $botMsg);
        $ordersAfterBoolFalse = Order::where('conversation_id', $conv->id)->count();

        // The evaluator answers "not triggered" as a JSON string.
        Cache::flush();
        $this->fakeEvaluator('{"triggered": "false", "variables": {"amount": "5000"}}');
        app(FlowPluginService::class)->executePlugins($bot, $conv, $botMsg);
        $ordersAfterStringFalse = Order::where('conversation_id', $conv->id)->get(['status', 'total_amount'])->toArray();

        $this->assertSame(0, $ordersAfterBoolFalse, 'control: boolean false creates no order');
        $this->assertSame([], $ordersAfterStringFalse, 'The evaluator answer "false" (string) created a completed order');
    }

    public function test_evaluator_true_string_still_triggers_the_plugin(): void
    {
        Http::fake(['*' => Http::response(['ok' => true])]);
        [$bot, $conv, $botMsg] = $this->seedPluginFixture();

        $this->fakeEvaluator('{"triggered": "true", "variables": {"amount": "5000"}}');
        app(FlowPluginService::class)->executePlugins($bot, $conv, $botMsg);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request->data()['text'] ?? '', '5000'));
    }
}
