<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\BotHITLSettings;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Services\FacebookService;
use App\Services\LeadRecoveryService;
use App\Services\LINEService;
use App\Services\OpenRouterService;
use App\Services\ResponseHoursService;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * B1 retirement of the old autonomous follow-up sender.
 *
 * The unit side pins the service contract after retirement:
 *   - findEligibleConversations is always empty, whatever the legacy toggle.
 *   - processRecovery returns false and touches nothing.
 *   - sendStaticFollowUp keeps working as the generic outage transport with
 *     the same constructor signature.
 */
class LeadRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadRecoveryService $service;

    private LINEService $lineService;

    private TelegramService $telegramService;

    private FacebookService $facebookService;

    private ResponseHoursService $responseHoursService;

    private User $user;

    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock services — constructor signature must stay the same (B1)
        $this->lineService = Mockery::mock(LINEService::class);
        $this->telegramService = Mockery::mock(TelegramService::class);
        $this->facebookService = Mockery::mock(FacebookService::class);
        $this->responseHoursService = Mockery::mock(ResponseHoursService::class);
        $openRouterService = Mockery::mock(OpenRouterService::class);

        $this->service = new LeadRecoveryService(
            $this->lineService,
            $this->telegramService,
            $this->facebookService,
            $this->responseHoursService,
            $openRouterService
        );

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create([
            'user_id' => $this->user->id,
            'channel_type' => 'telegram',
        ]);
    }

    protected function tearDown(): void
    {
        // Rollback the DB transaction BEFORE Mockery verifies: a violated
        // shouldNotReceive must not abort the RefreshDatabase rollback.
        parent::tearDown();
        Mockery::close();
    }

    /**
     * Create bot settings with HITL settings for lead recovery.
     */
    private function createBotSettingsWithHITL(
        Bot $bot,
        bool $leadRecoveryEnabled = true,
        int $timeoutHours = 4,
        int $maxAttempts = 3,
        ?string $leadRecoveryMessage = null,
        string $mode = 'static'
    ): BotHITLSettings {
        $botSetting = BotSetting::create([
            'bot_id' => $bot->id,
        ]);

        return BotHITLSettings::create([
            'bot_setting_id' => $botSetting->id,
            'lead_recovery_enabled' => $leadRecoveryEnabled,
            'lead_recovery_timeout_hours' => $timeoutHours,
            'lead_recovery_max_attempts' => $maxAttempts,
            'lead_recovery_message' => $leadRecoveryMessage,
            'lead_recovery_mode' => $mode,
        ]);
    }

    /**
     * A room that the old silent-room picker would have picked.
     */
    private function eligibleLookingRoom(Bot $bot): Conversation
    {
        return Conversation::factory()->create([
            'bot_id' => $bot->id,
            'channel_type' => $bot->channel_type,
            'external_customer_id' => 'legacy-customer-1',
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 0,
            'last_message_at' => now()->subHours(30),
            'last_recovery_at' => null,
        ]);
    }

    // =========================================================================
    // Retirement: the old picker never picks anything
    // =========================================================================

    public function test_find_eligible_conversations_returns_nothing_even_with_old_toggle_true(): void
    {
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);
        $this->eligibleLookingRoom($this->bot);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(0, $result, 'the retired picker must never return a room');
    }

    public function test_find_eligible_conversations_returns_nothing_without_settings(): void
    {
        $this->eligibleLookingRoom($this->bot);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(0, $result);
    }

    // =========================================================================
    // Retirement: processRecovery is a hard no-op
    // =========================================================================

    public function test_process_recovery_returns_false_without_sending_or_logging(): void
    {
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);
        $conversation = $this->eligibleLookingRoom($this->bot);

        // Nothing may send: the three channel mocks stay untouched. The
        // response-hours mock is lenient — its non-use is proven by the
        // no-op result below, and on the pre-change code a strict mock
        // would only add noise to the primary RED failure.
        $this->responseHoursService->shouldReceive('checkResponseHours')
            ->zeroOrMoreTimes()
            ->andReturn(['allowed' => true, 'status' => 'within_test_hours']);
        $this->lineService->shouldNotReceive('push');
        $this->telegramService->shouldNotReceive('sendMessage');
        $this->facebookService->shouldNotReceive('sendMessage');

        $result = $this->service->processRecovery($conversation);

        $this->assertFalse($result);
        $this->assertDatabaseCount('lead_recovery_logs', 0);

        $conversation->refresh();
        $this->assertSame(0, $conversation->recovery_attempts);
        $this->assertNull($conversation->last_recovery_at);
    }

    // =========================================================================
    // Generic transport (used by outage notices) stays intact
    // =========================================================================

    public function test_static_follow_up_sends_via_line_with_retry_key(): void
    {
        $conversation = $this->eligibleLookingRoom($this->bot->forceFill(['channel_type' => 'line']));
        $message = 'ขออภัยค่ะ ระบบกลับมาใช้ได้แล้ว';

        $this->lineService->shouldReceive('generateRetryKey')->once()->andReturn('retry-key-1');
        $this->lineService->shouldReceive('push')
            ->once()
            ->with(
                Mockery::on(fn ($arg) => $arg->is($this->bot)),
                'legacy-customer-1',
                Mockery::on(fn ($arg) => count($arg) === 1 && $arg[0] === $message),
                'retry-key-1'
            );

        $this->assertTrue($this->service->sendStaticFollowUp($conversation, $message));
    }

    public function test_static_follow_up_sends_plain_text_telegram_without_parse_mode(): void
    {
        $conversation = $this->eligibleLookingRoom($this->bot);
        $message = 'ขออภัยครับพี่ ระบบกลับมาใช้ได้แล้ว รบกวนพิมพ์ใหม่อีกครั้งนะครับ';

        $this->telegramService->shouldReceive('sendMessage')
            ->once()
            ->with(
                Mockery::on(fn ($arg) => $arg->is($this->bot)),
                'legacy-customer-1',
                $message,
                ['parse_mode' => null]
            );

        $this->assertTrue($this->service->sendStaticFollowUp($conversation, $message, true));
    }

    public function test_static_follow_up_keeps_html_parse_mode_by_default(): void
    {
        $conversation = $this->eligibleLookingRoom($this->bot);
        $message = 'สวัสดีค่ะ';

        $this->telegramService->shouldReceive('sendMessage')
            ->once()
            ->with(
                Mockery::on(fn ($arg) => $arg->is($this->bot)),
                'legacy-customer-1',
                $message,
                []
            );

        $this->assertTrue($this->service->sendStaticFollowUp($conversation, $message));
    }

    public function test_static_follow_up_sends_via_facebook(): void
    {
        $conversation = $this->eligibleLookingRoom($this->bot->forceFill(['channel_type' => 'facebook']));
        $message = 'ขออภัยค่ะ ระบบกลับมาใช้ได้แล้วค่ะ';

        $this->facebookService->shouldReceive('sendMessage')
            ->once()
            ->with(
                Mockery::on(fn ($arg) => $arg->is($this->bot)),
                'legacy-customer-1',
                $message
            );

        $this->assertTrue($this->service->sendStaticFollowUp($conversation, $message));
    }

    public function test_static_follow_up_rejects_unsupported_channel(): void
    {
        $conversation = $this->eligibleLookingRoom($this->bot->forceFill(['channel_type' => 'web']));

        $this->lineService->shouldNotReceive('push');
        $this->telegramService->shouldNotReceive('sendMessage');
        $this->facebookService->shouldNotReceive('sendMessage');

        $this->assertFalse($this->service->sendStaticFollowUp($conversation, 'any text'));
    }

    public function test_static_follow_up_requires_external_customer_id(): void
    {
        $conversation = $this->eligibleLookingRoom($this->bot);
        $conversation->update(['external_customer_id' => '']);

        $this->lineService->shouldNotReceive('push');
        $this->telegramService->shouldNotReceive('sendMessage');
        $this->facebookService->shouldNotReceive('sendMessage');

        $this->assertFalse($this->service->sendStaticFollowUp($conversation, 'any text'));
    }

    public function test_static_follow_up_returns_false_when_channel_throws(): void
    {
        $conversation = $this->eligibleLookingRoom($this->bot);

        $this->telegramService->shouldReceive('sendMessage')
            ->once()
            ->andThrow(new \RuntimeException('network down'));

        $this->assertFalse($this->service->sendStaticFollowUp($conversation, 'any text'));
    }
}
