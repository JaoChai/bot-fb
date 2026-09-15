<?php

namespace Tests\Feature\CommerceSafety;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\Order;
use App\Models\ProductStock;
use App\Models\SlipVerification;
use App\Models\User;
use App\Services\AIService;
use App\Services\Chat\ConversationContextService;
use App\Services\CommerceSafety\CanonicalCartValidator;
use App\Services\CommerceSafety\CartValidation;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\CheckoutConsentPolicy;
use App\Services\CommerceSafety\CheckoutRenderer;
use App\Services\FlowPluginService;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\ResponseEnvelope;
use App\Services\LineWebhook\WebhookContext;
use App\Services\ModelCapabilityService;
use App\Services\MultipleBubblesService;
use App\Services\OpenRouterService;
use App\Services\Payment\SlipVerificationService;
use App\Services\PaymentFlexService;
use App\Services\RAGService;
use App\Services\StickerReplyService;
use App\Services\StockGuardService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

class CheckoutConsentTest extends TestCase
{
    use RefreshDatabase;

    private Bot $bot;

    private Conversation $conversation;

    /** @var array<string, ProductStock> */
    private array $products;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->bot = Bot::factory()->active()->create([
            'user_id' => User::factory()->owner()->create()->id,
        ]);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [],
        ]);
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'order',
            'name' => 'Checkout fixture',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => [],
        ]);
        config(["commerce_safety.bots.{$this->bot->id}" => [
            'mode' => 'enforce',
            'payment_plugin_ids' => [$plugin->id],
        ]]);

        $this->products = [
            'personal' => $this->product([
                'name' => 'Nolimit Level Up+ Personal',
                'slug' => 'personal',
                'stock_code' => 'NLMP',
                'aliases' => ['Personal'],
                'delivery_method' => 'stock',
                'price' => '1100.00',
                'vip_price' => '1000.00',
                'available_count' => 20,
            ]),
            'bm' => $this->product([
                'name' => 'Nolimit Level Up+ BM',
                'slug' => 'bm',
                'stock_code' => 'NLMBM',
                'aliases' => ['BM'],
                'delivery_method' => 'stock',
                'price' => '1100.00',
                'vip_price' => '1000.00',
                'available_count' => 20,
            ]),
            'page' => $this->product([
                'name' => 'Page',
                'slug' => 'page',
                'stock_code' => 'PAGE',
                'aliases' => ['เพจ'],
                'delivery_method' => 'support_link',
                'price' => '199.00',
                'vip_price' => null,
                'available_count' => null,
            ]),
            'g3d' => $this->product([
                'name' => 'G3D',
                'slug' => 'g3d',
                'stock_code' => 'G3D',
                'aliases' => ['ไก่'],
                'delivery_method' => 'stock',
                'price' => '50.00',
                'vip_price' => null,
                'available_count' => 50,
            ]),
        ];
    }

    #[Test]
    public function a_model_claim_cannot_confirm_without_an_actual_presented_customer_reply(): void
    {
        $outcome = $this->authority()->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 110000],
        ], 110000));

        $this->assertSame('confirm', $outcome->action);
        $this->assertSame('awaiting_confirm', $outcome->checkout->state);
        $this->assertSame([], $outcome->checkout->accepted);
        $this->assertNull($outcome->checkout->settled_event_id);
        $this->assertDatabaseCount('checkout_sessions', 1);
        Http::assertNothingSent();
    }

    #[Test]
    public function replies_before_presentation_or_from_another_scope_fail_closed(): void
    {
        $checkout = $this->proposePage()->checkout;
        $replyBeforePresented = $this->userMessage($this->conversation, 'ยืนยัน');

        $outcome = $this->authority()->accept($this->bot, $this->conversation, $replyBeforePresented);
        $this->assertNotSame('payment', $outcome->action);
        $this->assertSame([], $checkout->fresh()->accepted);

        $otherConversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $foreignReply = $this->userMessage($otherConversation, 'ยืนยัน');
        $this->assertSame('confirm', $this->authority()
            ->accept($this->bot, $this->conversation, $foreignReply)->action);

        $otherBot = Bot::factory()->active()->create();
        $this->assertSame('clarify', $this->authority()
            ->accept($otherBot, $this->conversation, $replyBeforePresented)->action);
        $this->assertSame([], $checkout->fresh()->accepted);
    }

    #[Test]
    public function old_revision_and_duplicate_message_replay_cannot_advance_consent(): void
    {
        $authority = $this->authority();
        $first = $this->proposePage();
        $oldChallenge = $this->present($first->checkout, 'confirm');
        $oldRevision = $first->checkout->revision;

        $changed = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Page', 'method' => 'none', 'qty' => 2, 'price_minor' => 19900],
        ], 39800));
        $this->assertGreaterThan($oldRevision, $changed->checkout->revision);
        $this->assertSame('confirm', $changed->action);

        $oldReply = $this->userMessage($this->conversation, 'ยืนยัน');
        $this->assertSame('confirm', $authority->accept($this->bot, $this->conversation, $oldReply)->action);
        $this->assertSame([], $changed->checkout->fresh()->accepted);

        $this->present($changed->checkout->fresh(), 'confirm');
        $reply = $this->userMessage($this->conversation, 'ยืนยันครับ');
        $accepted = $authority->accept($this->bot, $this->conversation, $reply);
        $this->assertSame('terms', $accepted->action);
        $this->assertSame($reply->id, $accepted->checkout->accepted['confirm']);

        $replayed = $authority->accept($this->bot, $this->conversation, $reply);
        $this->assertSame('terms', $replayed->action);
        $this->assertArrayNotHasKey('terms', $replayed->checkout->accepted);
        $this->assertNotNull($oldChallenge->id);
    }

    #[Test]
    public function whole_response_allowlists_reject_upsell_okay_and_conditional_support(): void
    {
        $authority = $this->authority();
        $checkout = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 110000],
        ], 110000))->checkout;
        $this->present($checkout, 'confirm');

        $okay = $this->userMessage($this->conversation, 'โอเคครับ');
        $this->assertSame('confirm', $authority->accept($this->bot, $this->conversation, $okay)->action);

        $confirm = $this->userMessage($this->conversation, '“ยืนยัน” ครับ!');
        $support = $authority->accept($this->bot, $this->conversation, $confirm);
        $this->assertSame('support_delay', $support->action);
        $this->present($support->checkout, 'support_delay');

        $conditional = $this->userMessage($this->conversation, 'ตกลง แต่ต้องวันนี้');
        $rejected = $authority->accept($this->bot, $this->conversation, $conditional);
        $this->assertSame('support_delay', $rejected->action);
        $this->assertArrayNotHasKey('support_delay', $rejected->checkout->accepted);

        $unconditional = $this->userMessage($this->conversation, 'โอเคครับ');
        $terms = $authority->accept($this->bot, $this->conversation, $unconditional);
        $this->assertSame('terms', $terms->action);
        $this->assertSame($unconditional->id, $terms->checkout->accepted['support_delay']);
    }

    #[Test]
    public function one_reply_cannot_accept_a_terms_challenge_that_was_never_presented(): void
    {
        $authority = $this->authority();
        $checkout = $this->proposePage()->checkout;
        $this->present($checkout, 'confirm');
        $reply = $this->userMessage($this->conversation, 'ยืนยัน');

        $terms = $authority->accept($this->bot, $this->conversation, $reply);
        $this->assertSame('terms', $terms->action);
        $this->assertSame($reply->id, $terms->checkout->accepted['confirm']);

        $sameReply = $authority->accept($this->bot, $this->conversation, $reply);
        $this->assertSame('terms', $sameReply->action);
        $this->assertArrayNotHasKey('terms', $sameReply->checkout->accepted);
    }

    #[Test]
    public function proposals_merge_new_skus_but_replace_existing_sku_and_retain_page(): void
    {
        $authority = $this->authority();
        $initial = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 110000],
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 129900));

        $switched = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Personal', 'method' => 'topup', 'qty' => 2, 'price_minor' => 110000],
        ], 220000));
        $this->assertSame('ack', $switched->action);
        $this->assertSame(2, $switched->checkout->revision);
        $this->assertSame(['NLMP', 'PAGE'], array_column($switched->checkout->items, 'sku'));
        $this->assertSame([2, 1], array_column($switched->checkout->items, 'qty'));
        $this->assertSame(239900, $switched->checkout->total_minor);

        $additive = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 2, 'price_minor' => 5000],
        ], 10000));
        $this->assertSame(3, $additive->checkout->revision);
        $this->assertSame(['NLMP', 'PAGE', 'G3D'], array_column($additive->checkout->items, 'sku'));
        $this->assertSame(249900, $additive->checkout->total_minor);
    }

    #[Test]
    public function changing_quantity_after_payment_instructions_creates_a_fresh_revision(): void
    {
        $authority = $this->authority();
        $checkout = $this->proposePage()->checkout;
        $this->present($checkout, 'confirm');
        $terms = $authority->accept(
            $this->bot,
            $this->conversation,
            $this->userMessage($this->conversation, 'ยืนยัน'),
        );
        $this->present($terms->checkout, 'terms');
        $payment = $authority->accept(
            $this->bot,
            $this->conversation,
            $this->userMessage($this->conversation, 'ยอมรับ'),
        );
        $this->assertSame('payment', $payment->action);
        $revisionBefore = $payment->checkout->revision;

        $changed = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Page', 'method' => 'none', 'qty' => 2, 'price_minor' => 19900],
        ], 39800));

        $this->assertGreaterThan($revisionBefore, $changed->checkout->revision);
        $this->assertSame('confirm', $changed->action);
        $this->assertSame([], $changed->checkout->accepted);
        $this->assertNull($changed->checkout->presented_at);
    }

    #[Test]
    public function a_new_proposal_revalidates_untouched_lines_after_entitlement_changes(): void
    {
        $this->conversation->update(['memory_notes' => [[
            'id' => '00000000-0000-0000-0000-000000000027',
            'type' => 'memory',
            'source' => 'vip_manual',
            'content' => 'trusted source shape',
        ]]]);
        $authority = $this->authority();
        $vip = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 100000],
        ], 100000));
        $this->assertSame(100000, $vip->checkout->total_minor);

        $this->conversation->update(['memory_notes' => []]);
        $changed = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900));

        $this->assertSame('confirm', $changed->action);
        $this->assertSame(129900, $changed->checkout->total_minor);
        $this->assertSame(110000, $changed->checkout->items[0]['price_minor']);
        $this->assertTrue($changed->checkout->requirements['support_delay']);
        $this->assertTrue($changed->checkout->requirements['terms']);
    }

    #[Test]
    public function a_stale_reply_cannot_accept_after_entitlement_changes_before_consent(): void
    {
        $this->conversation->update(['memory_notes' => [[
            'id' => '00000000-0000-0000-0000-000000000028',
            'type' => 'memory',
            'source' => 'vip_manual',
            'content' => 'trusted source shape',
        ]]]);
        $authority = $this->authority();
        $vip = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 100000],
        ], 100000));
        $this->present($vip->checkout, 'confirm');

        $this->conversation->update(['memory_notes' => []]);
        $staleReply = $this->userMessage($this->conversation, 'ยืนยัน');
        $fresh = $authority->accept($this->bot, $this->conversation, $staleReply);

        $this->assertSame('confirm', $fresh->action);
        $this->assertSame(2, $fresh->checkout->revision);
        $this->assertSame(110000, $fresh->checkout->total_minor);
        $this->assertArrayNotHasKey('confirm', $fresh->checkout->accepted);
        $this->assertStringContainsString('revision 2', $fresh->customerText);
        $this->assertNull($fresh->checkout->challenge_message_id);
    }

    #[Test]
    public function persisted_actual_message_consent_survives_a_tiny_llm_context_window(): void
    {
        $this->bot->update(['context_window' => 1]);
        $authority = $this->authority();
        $checkout = $this->proposePage()->checkout;
        $this->present($checkout, 'confirm');
        $reply = $this->userMessage($this->conversation, 'confirm');
        $terms = $authority->accept($this->bot, $this->conversation, $reply);

        for ($i = 0; $i < 5; $i++) {
            $this->conversation->messages()->create([
                'sender' => 'bot',
                'type' => 'text',
                'content' => "later {$i}",
            ]);
        }

        $this->assertSame($reply->id, $terms->checkout->fresh()->accepted['confirm']);
    }

    #[Test]
    public function vip_and_real_old_purchase_exempt_only_their_authorized_stages(): void
    {
        $vipNoteId = '00000000-0000-0000-0000-000000000026';
        $this->conversation->update(['memory_notes' => [[
            'id' => $vipNoteId,
            'type' => 'memory',
            'source' => 'vip_manual',
            'content' => 'free text must not be copied into authority',
        ]]]);
        $vipCart = $this->cart([
            ['name' => 'BM', 'method' => 'topup', 'qty' => 1, 'price_minor' => 100000],
        ], 100000);
        $vipRequirements = app(CheckoutConsentPolicy::class)
            ->requirements($this->bot, $this->conversation->fresh(), $vipCart->lines);

        $this->assertTrue($vipRequirements['topup_ack']);
        $this->assertFalse($vipRequirements['support_delay']);
        $this->assertFalse($vipRequirements['terms']);
        $this->assertContains($vipNoteId, $vipRequirements['sources']['vip_note_ids']);
        $this->assertStringNotContainsString('free text', json_encode($vipRequirements));

        $returning = Conversation::factory()->withCustomerProfile()->create([
            'bot_id' => $this->bot->id,
            'memory_notes' => [[
                'type' => 'memory',
                'source' => 'auto_entity_extraction',
                'content' => 'ลูกค้าเก่า VIP ราคา 1,000',
            ]],
        ]);
        $order = Order::factory()->create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $returning->id,
            'customer_profile_id' => $returning->customer_profile_id,
            'status' => 'completed',
        ]);
        $normalCart = $this->cartFor($returning, [
            ['name' => 'Personal', 'method' => 'card', 'qty' => 1, 'price_minor' => 110000],
        ], 110000);
        $oldRequirements = app(CheckoutConsentPolicy::class)
            ->requirements($this->bot, $returning, $normalCart->lines);

        $this->assertFalse($normalCart->vip);
        $this->assertTrue($oldRequirements['support_delay']);
        $this->assertFalse($oldRequirements['terms']);
        $this->assertSame($order->id, $oldRequirements['sources']['completed_order_id']);
    }

    #[Test]
    public function renderer_uses_canonical_full_names_and_existing_terms_bank_and_order_literals(): void
    {
        $checkout = $this->proposePage()->checkout;
        $renderer = app(CheckoutRenderer::class);

        $confirm = $renderer->render($checkout, 'confirm');
        $this->present($checkout, 'confirm');
        $termsOutcome = $this->authority()->accept(
            $this->bot,
            $this->conversation,
            $this->userMessage($this->conversation, 'ยืนยัน'),
        );
        $terms = $renderer->render($termsOutcome->checkout, 'terms');
        $this->present($termsOutcome->checkout, 'terms');
        $paymentOutcome = $this->authority()->accept(
            $this->bot,
            $this->conversation,
            $this->userMessage($this->conversation, 'ยอมรับ'),
        );
        $payment = $renderer->render($paymentOutcome->checkout, 'payment');

        $this->assertStringContainsString('revision '.$checkout->revision, $confirm);
        $this->assertStringContainsString('Page (199 x 1) = 199 บาท', $confirm);
        $this->assertStringContainsString('https://mhhacoursecontent.my.canva.site/ads-vance', $terms);
        $this->assertStringContainsString('ธนาคารกสิกรไทย (KBANK)', $payment);
        $this->assertStringContainsString('223-3-24880-3', $payment);
        $this->assertStringContainsString('หจก. มั่งมีทรัพย์ขายของออนไลน์', $payment);
        $this->assertStringContainsString(
            '[[ORDER]]{"items":[{"name":"Page","qty":1,"price":199}],"total":199}[[/ORDER]]',
            $payment,
        );
        $this->assertStringNotContainsString('method', $payment);
    }

    #[Test]
    public function customer_saying_transferred_never_sets_paid_or_settled_state(): void
    {
        $authority = $this->authority();
        $checkout = $this->proposePage()->checkout;
        $this->present($checkout, 'confirm');

        $outcome = $authority->accept(
            $this->bot,
            $this->conversation,
            $this->userMessage($this->conversation, 'โอนแล้ว'),
        );

        $this->assertSame('confirm', $outcome->action);
        $this->assertSame('awaiting_confirm', $checkout->fresh()->state);
        $this->assertNull($checkout->fresh()->settled_event_id);
    }

    #[Test]
    public function checkout_authority_fields_are_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        CheckoutSession::create([
            'state' => 'paid',
            'accepted' => ['terms' => 1],
            'settled_event_id' => '00000000-0000-0000-0000-000000000001',
            'fingerprint' => 'forged',
        ]);
    }

    #[Test]
    public function shadow_and_unconfigured_bots_do_not_create_checkout_state_while_hold_blocks_payment(): void
    {
        $cart = $this->cart([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);

        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'shadow']);
        $this->assertSame('clarify', $this->authority()->propose($this->bot, $this->conversation, $cart)->action);
        $this->assertDatabaseCount('checkout_sessions', 0);

        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'hold']);
        $hold = $this->authority()->propose($this->bot, $this->conversation, $cart);
        $this->assertSame('manual_hold', $hold->action);
        $this->assertStringNotContainsString('223-3-24880-3', $hold->customerText);
        $this->assertDatabaseCount('checkout_sessions', 0);

        $unconfigured = Bot::factory()->active()->create();
        $unconfiguredConversation = Conversation::factory()->create(['bot_id' => $unconfigured->id]);
        $this->assertSame('clarify', $this->authority()
            ->accept($unconfigured, $unconfiguredConversation, $this->userMessage($unconfiguredConversation, 'ยืนยัน'))
            ->action);
        $this->assertDatabaseCount('checkout_sessions', 0);
    }

    #[Test]
    public function synchronous_pipeline_marks_the_exact_challenge_only_after_delivery_succeeds(): void
    {
        Event::fake();
        $userMessage = $this->userMessage($this->conversation, 'เอา Page 1 ใบ');
        $modelMessage = $this->conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => "สรุปรายการ\n1. Page (199 x 1) = 199 บาท\nรวม: 199 บาท กรุณาพิมพ์ ยืนยัน",
        ]);
        $cart = $this->cart([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateAndSaveResponse')
            ->once()
            ->with($this->bot, $this->conversation, $userMessage)
            ->andReturn($modelMessage);
        $ai->shouldReceive('takeCommerceSafetyCartValidation')->once()->with($modelMessage)->andReturn($cart);
        $context = Mockery::mock(ConversationContextService::class);
        $context->shouldReceive('autoClearIfIdle')->once()->with($this->conversation);
        $response = new LineWebhookResponseService(
            $ai,
            Mockery::mock(OpenRouterService::class),
            Mockery::mock(StickerReplyService::class),
            $context,
            Mockery::mock(ModelCapabilityService::class),
            Mockery::mock(LINEService::class),
            Mockery::mock(SlipVerificationService::class),
        );
        $ctx = new WebhookContext($this->bot, $this->lineTextEvent());
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $userMessage;

        $response->generate($ctx);

        $checkout = CheckoutSession::sole();
        $modelMessage->refresh();
        $this->assertStringContainsString("revision {$checkout->revision}", $modelMessage->content);
        $this->assertSame($modelMessage->id, $checkout->challenge_message_id);
        $this->assertNull($checkout->presented_at);

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('checkout-retry-key');
        $line->shouldReceive('replyWithFallback')->once()->andReturn(['method' => 'reply', 'success' => true]);
        $paymentFlex = Mockery::mock(PaymentFlexService::class);
        $paymentFlex->shouldReceive('tryConvertToFlex')
            ->once()
            ->with($modelMessage->content, $this->conversation)
            ->andReturn($modelMessage->content);
        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->once()->with($this->bot)->andReturn(false);
        $flowPlugin = Mockery::mock(FlowPluginService::class);
        $flowPlugin->shouldReceive('executePlugins')->once();
        $leadRecovery = Mockery::mock(LeadRecoveryService::class);
        $leadRecovery->shouldReceive('markCustomerResponded')->once();

        (new LineWebhookOutputService($line, $leadRecovery, $bubbles, $paymentFlex, $flowPlugin))
            ->dispatch($ctx);

        $this->assertNotNull($checkout->fresh()->presented_at);
        $this->assertSame($modelMessage->id, $checkout->fresh()->challenge_message_id);
        Http::assertNothingSent();
    }

    #[Test]
    public function false_plain_and_bubble_delivery_leave_the_challenge_pending(): void
    {
        foreach (['plain', 'bubble'] as $delivery) {
            Event::fake();
            [$ctx, $checkout, $botMessage] = $this->pendingOutputContext();
            $line = Mockery::mock(LINEService::class);
            $bubbles = Mockery::mock(MultipleBubblesService::class);
            $paymentFlex = Mockery::mock(PaymentFlexService::class);
            $paymentFlex->shouldReceive('tryConvertToFlex')->once()->andReturn($botMessage->content);
            $bubbles->shouldReceive('isEnabled')->once()->andReturn($delivery === 'bubble');
            if ($delivery === 'bubble') {
                $bubbles->shouldReceive('parseIntoBubbles')->once()->andReturn([$botMessage->content]);
                $bubbles->shouldReceive('sendBubbles')->once()->andReturn(false);
            } else {
                $line->shouldReceive('generateRetryKey')->once()->andReturn('failed-send');
                $line->shouldReceive('replyWithFallback')->once()
                    ->andReturn(['method' => 'reply', 'success' => false]);
            }
            $flowPlugin = Mockery::mock(FlowPluginService::class);
            $flowPlugin->shouldReceive('executePlugins')->once();
            $leadRecovery = Mockery::mock(LeadRecoveryService::class);
            $leadRecovery->shouldReceive('markCustomerResponded')->once();

            (new LineWebhookOutputService($line, $leadRecovery, $bubbles, $paymentFlex, $flowPlugin))
                ->dispatch($ctx);

            $this->assertNull($checkout->fresh()->presented_at, $delivery);
            $this->assertSame($botMessage->id, $checkout->fresh()->challenge_message_id);
            $checkout->forceFill(['state' => 'cancelled'])->save();
        }
    }

    #[Test]
    public function late_stage_completion_and_same_second_pre_presentation_message_fail_closed(): void
    {
        $authority = $this->authority();
        $checkout = $this->proposePage()->checkout;
        $confirmChallenge = $this->present($checkout, 'confirm');
        $terms = $authority->accept(
            $this->bot,
            $this->conversation,
            $this->userMessage($this->conversation, 'ยืนยัน'),
        );
        $termsChallenge = $this->conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text', 'content' => 'terms challenge',
        ]);
        $authority->pending($terms->checkout, $terms->checkout->revision, $termsChallenge, 'terms');
        $authority->presented($checkout, $checkout->revision, $confirmChallenge);
        $this->assertNull($checkout->fresh()->presented_at);
        $this->assertSame($termsChallenge->id, $checkout->fresh()->challenge_message_id);

        $earlyReply = $this->userMessage($this->conversation, 'ยอมรับ');
        $earlyReply->forceFill(['created_at' => $termsChallenge->created_at])->save();
        $authority->presented($terms->checkout, $terms->checkout->revision, $termsChallenge);
        $rejected = $authority->accept($this->bot, $this->conversation, $earlyReply);
        $this->assertSame('terms', $rejected->action);
        $this->assertArrayNotHasKey('terms', $rejected->checkout->accepted);
    }

    #[Test]
    public function deleted_accepted_message_and_slip_under_review_fail_closed(): void
    {
        $authority = $this->authority();
        $checkout = $this->proposePage()->checkout;
        $this->present($checkout, 'confirm');
        $confirm = $this->userMessage($this->conversation, 'ยืนยัน');
        $terms = $authority->accept($this->bot, $this->conversation, $confirm);
        $this->assertSame('terms', $terms->action);
        $confirm->delete();
        $this->present($terms->checkout, 'terms');
        $closed = $authority->accept(
            $this->bot,
            $this->conversation,
            $this->userMessage($this->conversation, 'ยอมรับ'),
        );
        $this->assertSame('confirm', $closed->action);
        $this->assertSame([], $closed->checkout->accepted);

        SlipVerification::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'pending',
        ]);
        $revision = $closed->checkout->revision;
        $held = $authority->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'G3D', 'method' => 'none', 'qty' => 1, 'price_minor' => 5000],
        ], 5000));
        $this->assertSame('manual_hold', $held->action);
        $this->assertSame($revision, $held->checkout->revision);
        $this->assertSame(['PAGE'], array_column($held->checkout->items, 'sku'));
    }

    #[Test]
    public function mixed_aggregation_retains_ordinary_and_cart_edit_constituents_in_order(): void
    {
        $checkout = $this->proposePage()->checkout;
        $this->present($checkout, 'confirm');
        $question = $this->userMessage($this->conversation, 'Page ใช้ทำอะไร');
        $consent = $this->userMessage($this->conversation, 'ยืนยัน');
        $edit = $this->userMessage($this->conversation, 'เพิ่ม G3D 2 ชิ้น');
        $line = Mockery::mock(LINEService::class);
        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $job = new ProcessAggregatedMessages(
            $this->bot,
            $this->conversation,
            'mixed-group',
            (string) $this->conversation->external_customer_id,
        );
        $method = (new ReflectionClass($job))->getMethod('consumeCheckoutMessages');

        $result = $method->invoke($job, [$question->id, $consent->id, $edit->id], $line, $bubbles);

        $this->assertSame([$question->id, $consent->id, $edit->id], $result['remaining_message_ids']);
        $this->assertCount(0, $result['responses']);
        $this->assertSame('awaiting_confirm', $checkout->fresh()->state);
    }

    #[Test]
    public function aggregated_false_bubble_delivery_never_presents_the_next_stage(): void
    {
        $checkout = $this->proposePage()->checkout;
        $this->present($checkout, 'confirm');
        $consent = $this->userMessage($this->conversation, 'ยืนยัน');
        $line = Mockery::mock(LINEService::class);
        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->once()->andReturn(true);
        $bubbles->shouldReceive('parseIntoBubbles')->once()->andReturn(['terms']);
        $bubbles->shouldReceive('sendBubbles')->once()->andReturn(false);
        $paymentFlex = Mockery::mock(PaymentFlexService::class);
        $paymentFlex->shouldReceive('tryConvertToFlex')->once()->andReturnUsing(fn (string $text) => $text);
        $this->app->instance(PaymentFlexService::class, $paymentFlex);
        $job = new ProcessAggregatedMessages(
            $this->bot,
            $this->conversation,
            'failed-bubble',
            (string) $this->conversation->external_customer_id,
        );
        $method = (new ReflectionClass($job))->getMethod('consumeCheckoutMessages');

        $result = $method->invoke($job, [$consent->id], $line, $bubbles);

        $this->assertSame([], $result['remaining_message_ids']);
        $this->assertCount(1, $result['responses']);
        $this->assertNull($checkout->fresh()->presented_at);
        $this->assertSame('terms', $checkout->fresh()->challenge_action);
    }

    #[Test]
    public function aggregated_hold_strict_failure_replaces_the_original_payment_output(): void
    {
        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'hold']);
        $job = new ProcessAggregatedMessages(
            $this->bot,
            $this->conversation,
            'hold-invalid',
            (string) $this->conversation->external_customer_id,
        );
        $method = (new ReflectionClass($job))->getMethod('checkoutProposal');
        $invalid = new CartValidation(false, ['INVALID_PROPOSAL'], [], 0, false, hash('sha256', 'invalid'));

        $outcome = $method->invoke($job, [
            'content' => 'โอนเงินที่ 223-3-24880-3',
            'commerce_safety_cart_validation' => $invalid,
        ]);

        $this->assertSame('manual_hold', $outcome->action);
        $this->assertStringNotContainsString('223-3-24880-3', $outcome->customerText);
        $this->assertDatabaseCount('checkout_sessions', 0);
    }

    #[Test]
    public function off_and_shadow_preserve_output_while_hold_replaces_a_strict_failure(): void
    {
        foreach (['off', 'shadow'] as $mode) {
            config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode]);
            $original = "ข้อมูลตัวอย่าง\nรวมยอดโอน 199 บาท เลขบัญชี 223-3-24880-3";
            $cart = $mode === 'shadow' ? $this->cart([
                ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
            ], 19900) : null;
            $ctx = $this->generateWithInternalCart($original, $cart);
            $this->assertSame($original, $ctx->response->payload, $mode);
            $this->assertDatabaseCount('checkout_sessions', 0);
        }

        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'hold']);
        $invalid = new CartValidation(false, ['INVALID_PROPOSAL'], [], 0, false, hash('sha256', 'invalid'));
        $ctx = $this->generateWithInternalCart(
            "รวมยอดโอน 199 บาท\nธนาคารกสิกรไทย 223-3-24880-3",
            $invalid,
        );
        $this->assertStringNotContainsString('223-3-24880-3', $ctx->response->payload);
        $this->assertStringContainsString('ระบุชื่อสินค้า', $ctx->response->payload);
        $this->assertDatabaseCount('checkout_sessions', 0);
    }

    #[Test]
    public function actual_ai_off_and_shadow_outputs_remain_identical(): void
    {
        $this->bot->update(['context_window' => 10]);
        $content = "สรุปรายการ\n1. Page (199 x 1) = 199 บาท\nรวม: 199 บาท กรุณาพิมพ์ ยืนยัน";
        $this->mock(RAGService::class, function ($mock) use ($content): void {
            $mock->shouldReceive('generateResponse')->twice()->andReturn([
                'content' => $content,
                'model' => 'compatibility-test',
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]);
        });
        $this->mock(StockGuardService::class, function ($mock) use ($content): void {
            $mock->shouldReceive('validate')->twice()->andReturn([
                'blocked' => false,
                'content' => $content,
            ]);
        });
        $ai = app(AIService::class);

        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'off']);
        $off = $ai->generateResponse($this->bot, 'Page คืออะไร', $this->conversation);
        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'shadow']);
        $shadow = $ai->generateResponse($this->bot, 'เอา Page 1 ใบ', $this->conversation);

        $this->assertSame($content, $off['content']);
        $this->assertSame($content, $shadow['content']);
        $this->assertNull($off['commerce_safety_cart_validation']);
        $this->assertInstanceOf(CartValidation::class, $shadow['commerce_safety_cart_validation']);
        $this->assertTrue($shadow['commerce_safety_cart_validation']->valid);
        $this->assertDatabaseCount('checkout_sessions', 0);
    }

    #[Test]
    public function ordinary_model_prose_cannot_be_reparsed_into_checkout_authority(): void
    {
        $userMessage = $this->userMessage($this->conversation, 'Page คืออะไร');
        $modelMessage = $this->conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => "ตัวอย่างการสั่งซื้อ\n1. Page (199 x 1) = 199 บาท\nรวม: 199 บาท กรุณาพิมพ์ ยืนยัน",
        ]);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateAndSaveResponse')->once()->andReturn($modelMessage);
        $ai->shouldReceive('takeCommerceSafetyCartValidation')->once()->with($modelMessage)->andReturnNull();
        $context = Mockery::mock(ConversationContextService::class);
        $context->shouldReceive('autoClearIfIdle')->once();
        $response = new LineWebhookResponseService(
            $ai,
            Mockery::mock(OpenRouterService::class),
            Mockery::mock(StickerReplyService::class),
            $context,
            Mockery::mock(ModelCapabilityService::class),
            Mockery::mock(LINEService::class),
            Mockery::mock(SlipVerificationService::class),
        );
        $ctx = new WebhookContext($this->bot, $this->lineTextEvent());
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $userMessage;

        $response->generate($ctx);

        $this->assertDatabaseCount('checkout_sessions', 0);
        $this->assertSame($modelMessage->content, $ctx->response->payload);
    }

    #[Test]
    public function review_earlier_cart_edit_prevents_later_terms_consent_paying_the_old_cart(): void
    {
        $checkout = $this->proposePage()->checkout;
        $this->present($checkout, 'confirm');
        $this->authority()->accept($this->bot, $this->conversation, $this->userMessage($this->conversation, 'ยืนยัน'));
        $checkout->refresh();
        $this->present($checkout, 'terms');
        $edit = $this->userMessage($this->conversation, 'เพิ่ม G3D 2 ชิ้น');
        $consent = $this->userMessage($this->conversation, 'ยอมรับ');
        $job = new ProcessAggregatedMessages($this->bot, $this->conversation, 'review-edit', (string) $this->conversation->external_customer_id);
        $result = (new \ReflectionMethod($job, 'consumeCheckoutMessages'))->invoke($job, [$edit->id, $consent->id], Mockery::mock(LINEService::class), Mockery::mock(MultipleBubblesService::class));
        $this->assertSame([], $result['responses']);
        $this->assertSame([$edit->id, $consent->id], $result['remaining_message_ids']);
        $this->assertSame('awaiting_terms', $checkout->fresh()->state);
        $this->assertArrayNotHasKey('terms', $checkout->fresh()->accepted);
    }

    #[Test]
    public function review_generated_payment_instructions_without_a_parseable_total_are_blocked(): void
    {
        $texts = [
            'โอน 199 บาทเข้าบัญชี 223-3-24880-3 ได้เลยครับ',
            'บัญชี 223-3-24880-3 ครับ',
            'กรุณาชำระ 199 บาทได้เลย',
            'Please pay THB 199 now',
            'Transfer 199 THB please',
            'โอนยอดเดิมเข้าบัญชีเดิมได้เลยครับ',
            'โอน 199 เข้าบัญชีเดิมได้เลยครับ',
            'Please transfer the agreed amount to our bank account now.',
            'กรุณาชำระยอดก่อนหน้าเข้าบัญชีเดิมครับ',
            'โปรดจ่ายยอดที่ตกลงไว้เข้าธนาคารได้เลย',
            'Please remit the previous amount to the same account now.',
            'Pay the current amount into the agreed bank account.',
            'Kindly make payment of the agreed amount to our bank account.',
        ];
        foreach (['enforce', 'hold'] as $mode) {
            config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode]);
            foreach ($texts as $text) {
                $ctx = $this->generateWithInternalCart($text, null);
                $this->assertNotSame($text, $ctx->response->payload, $mode.': '.$text);
                $this->assertStringNotContainsString('223-3-24880-3', $ctx->response->payload);
                $job = new ProcessAggregatedMessages($this->bot, $this->conversation, 'review-output', (string) $this->conversation->external_customer_id);
                $outcome = (new \ReflectionMethod($job, 'checkoutProposal'))->invoke($job, ['content' => $text]);
                $this->assertNotNull($outcome);
                $this->assertSame('manual_hold', $outcome->action);
            }
        }
        foreach ([
            'Page ราคา 199 บาทครับ',
            'G3D 50 บาท ใช้ทำอะไรได้บ้าง',
            'รับโอนผ่านธนาคารครับ',
            'ตอนนี้ร้านยังไม่มี QR สำหรับรับชำระครับ',
            'นโยบายธนาคารสำหรับการโอนเงินเป็นอย่างไรครับ',
            'ยังไม่ต้องโอนเข้าบัญชีเดิมครับ',
            'ห้ามโอนยอดเดิมเข้าบัญชีเดิมครับ',
            'ห้ามโอนเข้าบัญชี 223-3-24880-3 ครับ',
            'ไม่ต้องชำระยอดที่ตกลงไว้ครับ',
            'กรุณาส่งสลิปหรือหลักฐานการชำระเงินให้ฝ่าย support',
            'กรุณาส่งสลิปจากบัญชี 223-3-24880-3 ให้ฝ่าย support',
            'ติดต่อฝ่าย support เพื่อสอบถามเรื่องการโอนผ่านธนาคาร',
            'นโยบายธนาคารสำหรับบัญชี 223-3-24880-3 เป็นอย่างไรครับ',
        ] as $text) {
            $this->assertSame($text, $this->generateWithInternalCart($text, null)->response->payload);
        }
        foreach (['off', 'shadow'] as $mode) {
            config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode]);
            foreach (array_slice($texts, 5, 3) as $text) {
                $this->assertSame($text, $this->generateWithInternalCart($text, null)->response->payload);
            }
        }
    }

    #[Test]
    public function review_actual_ai_detects_transfer_instructions_without_order_or_total(): void
    {
        $this->bot->update(['context_window' => 10]);
        $content = 'Please transfer the agreed amount to our bank account now.';
        $this->mock(RAGService::class)->shouldReceive('generateResponse')->once()->andReturn([
            'content' => $content, 'model' => 'test', 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
        ]);
        $this->mock(StockGuardService::class)->shouldReceive('validate')->once()->andReturn(['blocked' => false, 'content' => $content]);
        $result = app(AIService::class)->generateResponse($this->bot, 'เอา Page', $this->conversation);
        $this->assertInstanceOf(CartValidation::class, $result['commerce_safety_cart_validation']);
        $this->assertFalse($result['commerce_safety_cart_validation']->valid);
        $this->assertNotSame($content, $result['content']);
        $this->assertNull($result['order_payload']);
    }

    #[Test]
    public function review_ordinary_edit_is_not_consumed_when_catalog_revalidation_fails(): void
    {
        $checkout = $this->proposePage()->checkout;
        $this->products['page']->update(['manual_off' => true]);
        $edit = $this->userMessage($this->conversation, 'เพิ่ม G3D 2 ชิ้น');
        $job = new ProcessAggregatedMessages($this->bot, $this->conversation, 'review-invalid-edit', (string) $this->conversation->external_customer_id);
        $result = (new \ReflectionMethod($job, 'consumeCheckoutMessages'))->invoke($job, [$edit->id], Mockery::mock(LINEService::class), Mockery::mock(MultipleBubblesService::class));
        $this->assertSame([], $result['responses']);
        $this->assertSame([$edit->id], $result['remaining_message_ids']);
    }

    #[Test]
    public function review_payment_renderer_requires_current_persisted_payable_state(): void
    {
        $renderer = app(CheckoutRenderer::class);
        $unsaved = new CheckoutSession;
        $unsaved->forceFill(['state' => 'payable', 'items' => [], 'total_minor' => 19900]);
        $persisted = $this->proposePage()->checkout;
        $persisted->state = 'payable'; // An in-memory mutation is not authority.
        foreach ([$unsaved, $persisted] as $checkout) {
            try {
                $renderer->render($checkout, 'payment');
                $this->fail('Payment renderer accepted unpersisted payable state');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('payable checkout', $exception->getMessage());
            }
        }
    }

    private function authority(): CheckoutAuthority
    {
        return app(CheckoutAuthority::class);
    }

    /** @return array{WebhookContext,CheckoutSession,Message} */
    private function pendingOutputContext(): array
    {
        $checkout = $this->proposePage()->checkout;
        $botMessage = $this->conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => app(CheckoutRenderer::class)->render($checkout, 'confirm'),
            'metadata' => ['checkout_presentation' => [
                'checkout_id' => $checkout->getKey(),
                'revision' => $checkout->revision,
                'action' => 'confirm',
            ]],
        ]);
        $this->authority()->pending($checkout, $checkout->revision, $botMessage, 'confirm');
        $ctx = new WebhookContext($this->bot, $this->lineTextEvent());
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $this->userMessage($this->conversation, 'เอา Page');
        $ctx->response = ResponseEnvelope::text($botMessage->content);
        $ctx->metadata['bot_message'] = $botMessage;

        return [$ctx, $checkout, $botMessage];
    }

    private function generateWithInternalCart(string $content, ?CartValidation $cart): WebhookContext
    {
        $userMessage = $this->userMessage($this->conversation, 'คำถาม');
        $modelMessage = $this->conversation->messages()->create([
            'sender' => 'bot', 'type' => 'text', 'content' => $content,
        ]);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateAndSaveResponse')->once()->andReturn($modelMessage);
        if (in_array(config("commerce_safety.bots.{$this->bot->id}.mode"), ['enforce', 'hold'], true)) {
            $ai->shouldReceive('takeCommerceSafetyCartValidation')->once()->with($modelMessage)->andReturn($cart);
        }
        $context = Mockery::mock(ConversationContextService::class);
        $context->shouldReceive('autoClearIfIdle')->once();
        $response = new LineWebhookResponseService(
            $ai,
            Mockery::mock(OpenRouterService::class),
            Mockery::mock(StickerReplyService::class),
            $context,
            Mockery::mock(ModelCapabilityService::class),
            Mockery::mock(LINEService::class),
            Mockery::mock(SlipVerificationService::class),
        );
        $ctx = new WebhookContext($this->bot, $this->lineTextEvent());
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $userMessage;
        $response->generate($ctx);

        return $ctx;
    }

    private function proposePage()
    {
        return $this->authority()->propose($this->bot, $this->conversation, $this->cart([
            ['name' => 'Page', 'method' => 'none', 'qty' => 1, 'price_minor' => 19900],
        ], 19900));
    }

    private function present(CheckoutSession $checkout, string $action): Message
    {
        $challenge = $this->conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => app(CheckoutRenderer::class)->render($checkout, $action),
        ]);
        $this->authority()->pending($checkout, $checkout->revision, $challenge, $action);
        $this->authority()->presented($checkout, $checkout->revision, $challenge);

        return $challenge;
    }

    private function userMessage(Conversation $conversation, string $content): Message
    {
        $presented = CheckoutSession::query()
            ->where('conversation_id', $conversation->getKey())
            ->max('presented_event_timestamp');

        return $conversation->messages()->create([
            'sender' => 'user',
            'type' => 'text',
            'content' => $content,
            'event_timestamp' => max(now()->getTimestampMs(), ((int) $presented) + 1),
        ]);
    }

    private function lineTextEvent(): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'checkout-reply-token',
            'source' => ['userId' => $this->conversation->external_customer_id],
            'message' => ['type' => 'text', 'text' => 'เอา Page 1 ใบ', 'id' => 'checkout-message'],
            'webhookEventId' => 'checkout-event',
            'timestamp' => 1789400000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];
    }

    private function cart(array $lines, int $totalMinor): CartValidation
    {
        return $this->cartFor($this->conversation, $lines, $totalMinor);
    }

    private function cartFor(Conversation $conversation, array $lines, int $totalMinor): CartValidation
    {
        $cart = app(CanonicalCartValidator::class)
            ->validate($this->bot, $conversation, $lines, $totalMinor);
        $this->assertTrue($cart->valid, implode(', ', $cart->errors));

        return $cart;
    }

    private function product(array $attributes): ProductStock
    {
        return ProductStock::create(array_merge([
            'in_stock' => true,
            'manual_off' => false,
            'display_order' => count($this->products ?? []) + 1,
        ], $attributes));
    }
}
