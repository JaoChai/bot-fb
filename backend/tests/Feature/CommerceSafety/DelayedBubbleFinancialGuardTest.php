<?php

namespace Tests\Feature\CommerceSafety;

use App\Jobs\SendDelayedBubbleJob;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Order;
use App\Models\PaymentEffect;
use App\Models\SlipVerification;
use App\Models\User;
use App\Models\VerifiedPaymentEvent;
use App\Services\CommerceSafety\CustomerReplyPolicy;
use App\Services\CommerceSafety\FinancialOutputGuard;
use App\Services\CommerceSafety\PaymentProofService;
use App\Services\LINEService;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DelayedBubbleFinancialGuardTest extends TestCase
{
    use RefreshDatabase;

    private const FINANCIAL = 'กรุณาโอน 1 บาท ไปที่บัญชี 223-3-24880-3';

    private Bot $bot;

    private Conversation $conversation;

    private FlowPlugin $paymentPlugin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();

        $owner = User::factory()->owner()->create();
        $this->bot = Bot::factory()->active()->line()->create([
            'user_id' => $owner->id,
            'name' => 'Queued bot',
            'id' => 26,
        ]);
        BotSetting::create([
            'bot_id' => $this->bot->id,
            'multiple_bubbles_enabled' => true,
            'multiple_bubbles_min' => 1,
            'multiple_bubbles_max' => 3,
            'multiple_bubbles_delimiter' => '|||',
            'wait_multiple_bubbles_enabled' => true,
            'wait_multiple_bubbles_ms' => 1000,
        ]);
        $this->conversation = Conversation::factory()->line()->create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'U-delayed-guard',
        ]);
        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $this->paymentPlugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'name' => 'Payment fixture',
            'type' => 'telegram',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => [],
        ]);
    }

    public static function modeSwitches(): array
    {
        return [
            ['off', 'enforce'],
            ['off', 'hold'],
            ['shadow', 'enforce'],
            ['shadow', 'hold'],
        ];
    }

    #[DataProvider('modeSwitches')]
    public function test_financial_bubble_is_rechecked_against_current_mode_at_execution(
        string $queuedMode,
        string $executionMode,
    ): void {
        $this->scope($queuedMode);
        $sent = [];
        $executionBotName = null;
        $line = $this->lineMock($sent, $executionBotName);
        $service = new MultipleBubblesService($line, $this->paymentFlexPassthrough());

        $this->assertTrue($service->sendBubbles(
            $this->bot->fresh(),
            $this->conversation->external_customer_id,
            'reply-token',
            ['สวัสดีครับ', self::FINANCIAL],
            $this->conversation,
        ));

        $job = $this->queuedJob();
        $delay = $job->delay;
        $job = unserialize(serialize($job));
        $this->assertSame(2, $job->bubbleIndex);
        $this->assertSame(2, $job->totalBubbles);
        $this->assertSame($this->conversation->id, $job->conversationId);
        $this->assertEquals($delay, $job->delay);
        $this->bot->update(['name' => 'Reloaded at execution']);
        $this->scope($executionMode);

        $job->handle($line);

        $this->assertSame([[FinancialOutputGuard::DENIAL]], $sent);
        $this->assertNotContains([self::FINANCIAL], $sent);
        $this->assertSame('Reloaded at execution', $executionBotName);
        $this->assertNoAuthorityWasCreated();
    }

    public static function contactModeSwitches(): array
    {
        $cases = [];
        foreach (['off', 'shadow'] as $queued) {
            foreach (['off', 'shadow', 'enforce', 'hold'] as $execution) {
                foreach (['@notourshop99', 'ไม่ต้องชำระเงิน @notourshop99', 'https://lin.ee/h5wYpIf'] as $text) {
                    foreach ([false, true] as $legacyConversation) {
                        $cases[] = [$queued, $execution, $text, $legacyConversation];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('contactModeSwitches')]
    public function test_serialized_contacts_use_execution_mode_without_authority(
        string $queuedMode, string $executionMode, string $text, bool $legacyConversation,
    ): void {
        $this->scope($queuedMode);
        $sent = [];
        $name = null;
        $line = $this->lineMock($sent, $name);
        $service = new MultipleBubblesService($line, $this->paymentFlexPassthrough());
        $this->assertTrue($service->sendBubbles($this->bot->fresh(),
            $this->conversation->external_customer_id, 'reply-token',
            ['สวัสดีครับ', $text], $this->conversation));
        $job = unserialize(serialize($this->queuedJob()));
        // Queue payload bytes must also survive off/shadow execution unchanged.
        $job->bubbleContent = '  '.$job->bubbleContent."\n💬  ";
        $original = $job->bubbleContent;
        $job = unserialize(serialize($job));
        $this->bot->update(['name' => 'Reloaded at execution']);
        $this->scope($executionMode);
        $current = $this->conversation;
        if ($legacyConversation) {
            $this->conversation->update(['external_customer_id' => 'U-old-conversation']);
            $current = Conversation::factory()->line()->create([
                'bot_id' => 26, 'external_customer_id' => $job->userId,
            ]);
            $job->conversationId = null; // Legacy payloads resolve the current conversation.
        }
        $job = unserialize(serialize($job));
        Log::spy();
        $job->handle($line);

        $enforced = in_array($executionMode, ['enforce', 'hold'], true);
        $expected = $enforced ? match ($text) {
            '@notourshop99' => CustomerReplyPolicy::FALLBACK,
            'ไม่ต้องชำระเงิน @notourshop99' => FinancialOutputGuard::DENIAL,
            default => $original,
        } : $original;
        $this->assertSame([[$expected]], $sent);
        $this->assertSame('Reloaded at execution', $name);
        if ($text === '@notourshop99' && $executionMode !== 'off') {
            Log::shouldHaveReceived('warning')->with('Customer reply policy triggered', [
                'bot_id' => 26, 'conversation_id' => $current->id, 'reason' => 'contact_handle',
            ])->once();
        }
        $this->assertNoAuthorityWasCreated();
    }

    public static function unchangedModes(): array
    {
        return [['off'], ['shadow']];
    }

    #[DataProvider('unchangedModes')]
    public function test_off_and_shadow_execution_preserve_delayed_text_byte_for_byte(string $mode): void
    {
        $this->scope($mode);
        $text = "  สวัสดี\nโอนเป็นเพียงคำในข้อความเดิม 💬  ";
        $sent = [];
        $executionBotName = null;
        $line = $this->lineMock($sent, $executionBotName, expectReply: false);

        (new SendDelayedBubbleJob(
            $this->bot,
            $this->conversation->external_customer_id,
            $text,
            2,
            3,
        ))->handle($line);

        $this->assertSame([[$text]], $sent);
        $this->assertNoAuthorityWasCreated();
    }

    public function test_nonfinancial_delayed_bubble_still_sends_in_enforced_mode(): void
    {
        $this->scope('enforce');
        $sent = [];
        $executionBotName = null;
        $line = $this->lineMock($sent, $executionBotName, expectReply: false);

        (new SendDelayedBubbleJob(
            $this->bot,
            $this->conversation->external_customer_id,
            'สินค้าพร้อมจัดส่งครับ',
            2,
            2,
        ))->handle($line);

        $this->assertSame([['สินค้าพร้อมจัดส่งครับ']], $sent);
        $this->assertNoAuthorityWasCreated();
    }

    public function test_verified_receipt_text_cannot_borrow_provenance_on_generic_delayed_path(): void
    {
        $receipt = $this->conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'ได้รับเงิน 199 บาทแล้ว',
        ]);
        $slip = SlipVerification::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => $receipt->id,
            'trans_ref' => 'DELAYED-GUARD-RECEIPT',
            'amount' => '199.00',
            'receiver_account' => 'xxx-x-x4880-x',
            'status' => 'passed',
            'raw_response' => [],
        ]);
        app(PaymentProofService::class)->record(
            $this->bot,
            $this->conversation,
            $slip,
            $receipt,
            null,
        );
        $this->scope('enforce');
        $sent = [];
        $executionBotName = null;
        $line = $this->lineMock($sent, $executionBotName, expectReply: false);

        (new SendDelayedBubbleJob(
            $this->bot,
            $this->conversation->external_customer_id,
            $receipt->content,
            2,
            2,
        ))->handle($line);

        $this->assertSame([[FinancialOutputGuard::DENIAL]], $sent);
        $this->assertSame(1, VerifiedPaymentEvent::count());
        $this->assertSame(0, PaymentEffect::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(1, FlowPlugin::count());
        Http::assertNothingSent();
    }

    private function scope(string $mode): void
    {
        config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode,
            "commerce_safety.bots.{$this->bot->id}.payment_plugin_ids" => [$this->paymentPlugin->id],
        ]);
    }

    private function paymentFlexPassthrough(): PaymentFlexService
    {
        $paymentFlex = Mockery::mock(PaymentFlexService::class);
        $paymentFlex->shouldReceive('tryConvertToFlex')
            ->andReturnUsing(fn (string $bubble) => $bubble);

        return $paymentFlex;
    }

    /** @param array<int, array<int, string>> $sent */
    private function lineMock(array &$sent, ?string &$executionBotName, bool $expectReply = true): LINEService
    {
        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->andReturn('retry-key');
        if ($expectReply) {
            $line->shouldReceive('replyWithFallback')->once()->andReturn([
                'method' => 'reply',
                'success' => true,
            ]);
        }
        $line->shouldReceive('push')->once()->andReturnUsing(function (
            Bot $bot,
            string $userId,
            array $messages,
        ) use (&$sent, &$executionBotName): bool {
            $this->assertSame('U-delayed-guard', $userId);
            $executionBotName = $bot->name;
            $sent[] = $messages;

            return true;
        });

        return $line;
    }

    private function queuedJob(): SendDelayedBubbleJob
    {
        $job = null;
        Queue::assertPushed(SendDelayedBubbleJob::class, function (SendDelayedBubbleJob $queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        $this->assertInstanceOf(SendDelayedBubbleJob::class, $job);

        return $job;
    }

    private function assertNoAuthorityWasCreated(): void
    {
        $this->assertSame(1, FlowPlugin::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, CheckoutSession::count());
        $this->assertSame(0, PaymentEffect::count());
        $this->assertSame(0, VerifiedPaymentEvent::count());
        Http::assertNothingSent();
    }
}
