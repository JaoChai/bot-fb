<?php

namespace Tests\Feature\CommerceSafety;

use App\Models\Bot;
use App\Models\CommerceSafetyShadowObservation;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\ProductStock;
use App\Models\User;
use App\Services\AIService;
use App\Services\RAGService;
use App\Services\StockGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Proves what App\Services\CommerceSafety\ShadowObservationRecorder actually
 * records when wired into AIService::generateResponse — the "what does shadow
 * mode record today" facility behind php artisan bot26:shadow-report.
 */
class ShadowObservationRecordingTest extends TestCase
{
    use RefreshDatabase;

    private function trustedBot(string $mode): Bot
    {
        $bot = Bot::factory()->active()->create(['user_id' => User::factory()->owner()->create()->id, 'context_window' => 10]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'order', 'name' => 'fixture',
            'enabled' => true, 'trigger_condition' => 'always', 'config' => [],
        ]);
        config(["commerce_safety.bots.{$bot->id}" => ['mode' => $mode, 'payment_plugin_ids' => [$plugin->id]]]);

        return $bot;
    }

    private function generate(Bot $bot, string $content): array
    {
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $this->mock(RAGService::class)->shouldReceive('generateResponse')->once()->andReturn([
            'content' => $content, 'model' => 'test', 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0],
        ]);
        $this->mock(StockGuardService::class)->shouldReceive('validate')->andReturn(['blocked' => false]);

        return app(AIService::class)->generateResponse($bot, 'hello', $conversation);
    }

    #[Test]
    public function test_shadow_mode_records_one_valid_cart_proposal_observation(): void
    {
        Http::preventStrayRequests();
        $bot = $this->trustedBot('shadow');
        ProductStock::create(['name' => 'Page', 'slug' => 'page', 'stock_code' => 'PAGE', 'aliases' => [], 'delivery_method' => 'support_link', 'price' => 199, 'is_active' => true]);

        $this->generate($bot, 'สรุปรายการ [[ORDER]]{"items":[{"name":"Page","qty":1,"price":199}],"total":199}[[/ORDER]]');

        $this->assertDatabaseCount('commerce_safety_shadow_observations', 1);
        $this->assertDatabaseHas('commerce_safety_shadow_observations', [
            'bot_id' => $bot->id, 'category' => 'cart_proposal', 'outcome' => 'valid', 'reason' => null,
        ]);
    }

    #[Test]
    public function test_shadow_mode_records_one_row_per_invalid_cart_reason_code(): void
    {
        Http::preventStrayRequests();
        $bot = $this->trustedBot('shadow');

        $this->generate($bot, 'สรุปรายการ [[ORDER]]{"items":[{"name":"missing product","qty":1,"price":50}],"total":50}[[/ORDER]]');

        $rows = CommerceSafetyShadowObservation::where('bot_id', $bot->id)->get();
        $this->assertGreaterThanOrEqual(1, $rows->count());
        foreach ($rows as $row) {
            $this->assertSame('cart_proposal', $row->category);
            $this->assertSame('invalid', $row->outcome);
            $this->assertNotNull($row->reason);
        }
        $this->assertDatabaseHas('commerce_safety_shadow_observations', [
            'bot_id' => $bot->id, 'category' => 'cart_proposal', 'outcome' => 'invalid', 'reason' => 'UNKNOWN_OR_AMBIGUOUS_PRODUCT',
        ]);
    }

    #[Test]
    public function test_off_enforce_and_hold_modes_never_write_shadow_observations(): void
    {
        Http::preventStrayRequests();
        ProductStock::create(['name' => 'Page', 'slug' => 'page', 'stock_code' => 'PAGE', 'aliases' => [], 'delivery_method' => 'support_link', 'price' => 199, 'is_active' => true]);
        $content = 'สรุปรายการ [[ORDER]]{"items":[{"name":"Page","qty":1,"price":199}],"total":199}[[/ORDER]]';

        foreach (['off', 'enforce', 'hold'] as $mode) {
            $bot = $this->trustedBot($mode);
            $this->generate($bot, $content);
        }

        $this->assertDatabaseCount('commerce_safety_shadow_observations', 0);
    }

    #[Test]
    public function test_observations_are_scoped_per_bot(): void
    {
        Http::preventStrayRequests();
        ProductStock::create(['name' => 'Page', 'slug' => 'page', 'stock_code' => 'PAGE', 'aliases' => [], 'delivery_method' => 'support_link', 'price' => 199, 'is_active' => true]);
        $content = 'สรุปรายการ [[ORDER]]{"items":[{"name":"Page","qty":1,"price":199}],"total":199}[[/ORDER]]';

        $shadowBot = $this->trustedBot('shadow');
        $offBot = $this->trustedBot('off');
        $this->generate($shadowBot, $content);
        $this->generate($offBot, $content);

        $this->assertDatabaseCount('commerce_safety_shadow_observations', 1);
        $this->assertDatabaseHas('commerce_safety_shadow_observations', ['bot_id' => $shadowBot->id]);
        $this->assertDatabaseMissing('commerce_safety_shadow_observations', ['bot_id' => $offBot->id]);
    }
}
