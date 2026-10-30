<?php

namespace Tests\Feature\Payment;

use App\Exceptions\DeliveryAlreadyHandledException;
use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\Delivery\AccountDeliveryService;
use App\Services\Delivery\StockPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithStockPool;
use Tests\TestCase;

/**
 * When a push outcome is unknown (connection error/timeout — no HTTP status),
 * the item may already be with the customer: the delivery must not go back to
 * "reserved", because canceling a reserved delivery returns it to stock.
 * Retrying the delivery must reuse the same X-Line-Retry-Key so the provider
 * cannot deliver the same message twice.
 */
class DeliveryPushOutcomeUnknownTest extends TestCase
{
    use InteractsWithStockPool;
    use RefreshDatabase;

    private AccountDelivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStockPool();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $owner->id, 'channel_type' => 'line', 'channel_access_token' => 'dummy-line-token']);
        $conv = Conversation::factory()->create(['bot_id' => $bot->id, 'channel_type' => 'line', 'external_customer_id' => 'Udummy1']);
        $slip = SlipVerification::create(['bot_id' => $bot->id, 'conversation_id' => $conv->id, 'amount' => 1299, 'status' => 'passed']);
        $this->seedAvailable(10, 'NLMP', 'DUMMYCRED-uid10|pass10|mail|2fa');
        app(StockPoolService::class)->reserveOne('NLMP', '1');
        $this->delivery = AccountDelivery::create(['bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id, 'status' => AccountDelivery::STATUS_RESERVED, 'amount' => 1299]);
        $this->delivery->items()->create(['product_name' => 'Item', 'stock_code' => 'NLMP', 'kind' => 'stock',
            'qty' => 1, 'stock_item_id' => 10, 'status' => 'reserved']);
    }

    public function test_unknown_push_outcome_never_returns_sent_item_to_stock(): void
    {
        // The provider records the push body (= it received it), then the client loses the response.
        // After the first attempt the fake switches to success for the owner's retry.
        $phase = 'fail';
        $firstRetryKey = null;
        $retryKeys = [];
        $bodies = [];
        Http::fake(function (Request $r) use (&$firstRetryKey, &$retryKeys, &$bodies, &$phase) {
            if (str_contains($r->url(), 'api.line.me') && str_contains($r->url(), '/message/push')) {
                if ($phase === 'fail') {
                    $firstRetryKey = $r->header('X-Line-Retry-Key')[0] ?? null;
                    throw new ConnectionException('cURL error 28: Operation timed out after response sent (simulated)');
                }
                $retryKeys[] = $r->header('X-Line-Retry-Key')[0] ?? null;
                $bodies[] = $r->body();
            }

            return Http::response(['ok' => true]);
        });

        try {
            app(AccountDeliveryService::class)->deliver($this->delivery, 'admin');
            $this->fail('expected the push failure to surface');
        } catch (ConnectionException) {
        }

        $fresh = $this->delivery->fresh();
        $this->assertSame('delivery_unknown', $fresh->status,
            'An unknown push outcome went back to reserved — cancel could return a sent item to stock');
        $this->assertSame(0, DB::connection('mhha_acc')->table('items_sold')->count());
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_reserved')->count());

        try {
            app(AccountDeliveryService::class)->cancel($fresh, 'admin');
            $this->fail('cancel must refuse an unknown-outcome delivery');
        } catch (DeliveryAlreadyHandledException) {
        }

        $this->assertNull(
            DB::connection('mhha_acc')->table('items_available')->where('detail', 'like', '%DUMMYCRED-uid10%')->value('detail'),
            'The credential that already reached the provider was returned to available stock',
        );

        // Owner retries — the same logical push, so the same retry key.
        $phase = 'ok';
        app(AccountDeliveryService::class)->deliver($this->delivery->fresh(), 'admin');

        $this->assertSame(AccountDelivery::STATUS_DELIVERED, $this->delivery->fresh()->status);
        $this->assertCount(1, $bodies);
        $this->assertStringContainsString('DUMMYCRED-uid10', $bodies[0]);
        $this->assertSame(1, DB::connection('mhha_acc')->table('items_sold')->count());
        $this->assertSame($firstRetryKey, $retryKeys[0], 'The retry used a different X-Line-Retry-Key — the provider could double-send');
    }
}
