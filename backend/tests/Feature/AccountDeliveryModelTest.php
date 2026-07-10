<?php

namespace Tests\Feature;

use App\Models\AccountDelivery;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeliveryModelTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixtures(): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'channel_type' => 'line']);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);
        $slip = SlipVerification::create([
            'bot_id' => $bot->id, 'conversation_id' => $conversation->id,
            'amount' => 1100, 'status' => 'passed',
        ]);

        return [$bot, $conversation, $slip];
    }

    public function test_creates_delivery_with_items(): void
    {
        [$bot, $conv, $slip] = $this->seedFixtures();

        $delivery = AccountDelivery::create([
            'bot_id' => $bot->id,
            'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id,
            'status' => AccountDelivery::STATUS_RESERVING,
            'amount' => 1100,
        ]);
        $delivery->items()->create([
            'product_name' => 'Nolimit ส่วนตัว', 'stock_code' => 'NLMP',
            'kind' => 'stock', 'qty' => 1, 'stock_item_id' => 42, 'status' => 'reserved',
        ]);

        $this->assertSame(1, $delivery->items()->count());
        $this->assertSame('NLMP', $delivery->items->first()->stock_code);
    }

    public function test_slip_verification_id_is_unique(): void
    {
        [$bot, $conv, $slip] = $this->seedFixtures();
        $attrs = [
            'bot_id' => $bot->id, 'conversation_id' => $conv->id,
            'slip_verification_id' => $slip->id, 'status' => 'reserving',
        ];
        AccountDelivery::create($attrs);

        $this->expectException(UniqueConstraintViolationException::class);
        AccountDelivery::create($attrs);
    }

    public function test_product_stock_has_delivery_columns(): void
    {
        $p = ProductStock::create([
            'name' => 'Nolimit ส่วนตัว', 'slug' => 'nolimit-personal',
            'aliases' => ['Nolimit', 'NLM ส่วนตัว'], 'in_stock' => true,
            'display_order' => 1, 'stock_code' => 'NLMP', 'delivery_method' => 'stock',
        ]);

        $this->assertSame('NLMP', $p->fresh()->stock_code);
        $this->assertSame('stock', $p->fresh()->delivery_method);
    }
}
