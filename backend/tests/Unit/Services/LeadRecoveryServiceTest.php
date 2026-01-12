<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\BotHITLSettings;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\LeadRecoveryLog;
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

class LeadRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private LeadRecoveryService $service;
    private User $user;
    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock services
        $lineService = Mockery::mock(LINEService::class);
        $telegramService = Mockery::mock(TelegramService::class);
        $facebookService = Mockery::mock(FacebookService::class);
        $responseHoursService = Mockery::mock(ResponseHoursService::class);
        $openRouterService = Mockery::mock(OpenRouterService::class);

        $this->service = new LeadRecoveryService(
            $lineService,
            $telegramService,
            $facebookService,
            $responseHoursService,
            $openRouterService
        );

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
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

    // =========================================================================
    // Test: findEligibleConversations
    // =========================================================================

    public function test_finds_eligible_conversations(): void
    {
        // Setup: Bot with lead recovery enabled, timeout 4 hours
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);

        // Create conversation inactive for 5 hours (past the 4 hour timeout)
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 0,
            'last_message_at' => now()->subHours(5),
            'last_recovery_at' => null,
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(1, $result);
        $this->assertEquals($conversation->id, $result->first()->id);
    }

    public function test_excludes_hitl_conversations(): void
    {
        // Setup: Bot with lead recovery enabled
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);

        // Create conversation with is_handover = true
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
            'is_handover' => true,
            'recovery_attempts' => 0,
            'last_message_at' => now()->subHours(5),
            'last_recovery_at' => null,
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(0, $result);
    }

    public function test_excludes_conversations_at_max_attempts(): void
    {
        // Setup: Bot with max_attempts = 3
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);

        // Create conversation with recovery_attempts = 3 (at max)
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 3,
            'last_message_at' => now()->subHours(5),
            'last_recovery_at' => null,
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(0, $result);
    }

    public function test_respects_24_hour_cooldown(): void
    {
        // Setup: Bot with lead recovery enabled
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);

        // Create conversation with last_recovery_at within 24 hours
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 1,
            'last_message_at' => now()->subHours(5),
            'last_recovery_at' => now()->subHours(12), // Only 12 hours ago
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(0, $result);
    }

    public function test_includes_conversation_after_24_hour_cooldown(): void
    {
        // Setup: Bot with lead recovery enabled
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);

        // Create conversation with last_recovery_at over 24 hours ago
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 1,
            'last_message_at' => now()->subHours(30),
            'last_recovery_at' => now()->subHours(25), // 25 hours ago (past cooldown)
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(1, $result);
        $this->assertEquals($conversation->id, $result->first()->id);
    }

    public function test_excludes_closed_conversations(): void
    {
        // Setup: Bot with lead recovery enabled
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);

        // Create closed conversation
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'closed',
            'is_handover' => false,
            'recovery_attempts' => 0,
            'last_message_at' => now()->subHours(5),
            'last_recovery_at' => null,
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(0, $result);
    }

    public function test_excludes_recently_active_conversations(): void
    {
        // Setup: Bot with 4 hour timeout
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);

        // Create conversation active 2 hours ago (within timeout)
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 0,
            'last_message_at' => now()->subHours(2),
            'last_recovery_at' => null,
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(0, $result);
    }

    // =========================================================================
    // Test: generateStaticMessage
    // =========================================================================

    public function test_generates_static_message(): void
    {
        // Setup: Bot with lead_recovery_message set
        $customMessage = 'สวัสดีครับ ยังสนใจสินค้าของเราอยู่ไหมครับ?';
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3, $customMessage);

        // Force reload to get the settings
        $this->bot->load('settings.hitlSettings');

        $result = $this->service->generateStaticMessage($this->bot);

        $this->assertEquals($customMessage, $result);
    }

    public function test_uses_default_message_when_not_configured(): void
    {
        // Setup: Bot with lead_recovery_message = null
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3, null);

        // Force reload to get the settings
        $this->bot->load('settings.hitlSettings');

        $result = $this->service->generateStaticMessage($this->bot);

        $expectedDefault = 'สวัสดีค่ะ ไม่ทราบว่ายังสนใจอยู่ไหมคะ? หากมีข้อสงสัยสามารถสอบถามได้เลยนะคะ';
        $this->assertEquals($expectedDefault, $result);
    }

    public function test_uses_default_message_when_no_settings(): void
    {
        // Bot without any settings
        $result = $this->service->generateStaticMessage($this->bot);

        $expectedDefault = 'สวัสดีค่ะ ไม่ทราบว่ายังสนใจอยู่ไหมคะ? หากมีข้อสงสัยสามารถสอบถามได้เลยนะคะ';
        $this->assertEquals($expectedDefault, $result);
    }

    public function test_uses_default_message_when_empty_string(): void
    {
        // Setup: Bot with lead_recovery_message = '' (empty string)
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3, '');

        // Force reload to get the settings
        $this->bot->load('settings.hitlSettings');

        $result = $this->service->generateStaticMessage($this->bot);

        $expectedDefault = 'สวัสดีค่ะ ไม่ทราบว่ายังสนใจอยู่ไหมคะ? หากมีข้อสงสัยสามารถสอบถามได้เลยนะคะ';
        $this->assertEquals($expectedDefault, $result);
    }

    // =========================================================================
    // Test: logRecoveryAttempt
    // =========================================================================

    public function test_logs_recovery_attempt(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'recovery_attempts' => 0,
        ]);

        $log = $this->service->logRecoveryAttempt(
            $conversation,
            'static',
            'Test message',
            'sent',
            null
        );

        $this->assertInstanceOf(LeadRecoveryLog::class, $log);
        $this->assertDatabaseHas('lead_recovery_logs', [
            'conversation_id' => $conversation->id,
            'bot_id' => $this->bot->id,
            'attempt_number' => 1,
            'message_mode' => 'static',
            'message_sent' => 'Test message',
            'delivery_status' => 'sent',
            'error_message' => null,
            'customer_responded' => false,
        ]);
    }

    public function test_logs_recovery_attempt_with_error(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'recovery_attempts' => 1,
        ]);

        $log = $this->service->logRecoveryAttempt(
            $conversation,
            'ai',
            '',
            'failed',
            'Network timeout'
        );

        $this->assertDatabaseHas('lead_recovery_logs', [
            'conversation_id' => $conversation->id,
            'attempt_number' => 2,
            'message_mode' => 'ai',
            'delivery_status' => 'failed',
            'error_message' => 'Network timeout',
        ]);
    }

    public function test_logs_recovery_attempt_increments_attempt_number(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'recovery_attempts' => 2,
        ]);

        $log = $this->service->logRecoveryAttempt(
            $conversation,
            'static',
            'Test message',
            'sent'
        );

        $this->assertEquals(3, $log->attempt_number);
    }

    // =========================================================================
    // Test: updateConversationAfterRecovery
    // =========================================================================

    public function test_updates_conversation_after_recovery(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'recovery_attempts' => 0,
            'last_recovery_at' => null,
        ]);

        $this->service->updateConversationAfterRecovery($conversation);

        $conversation->refresh();

        $this->assertEquals(1, $conversation->recovery_attempts);
        $this->assertNotNull($conversation->last_recovery_at);
        $this->assertTrue($conversation->last_recovery_at->isToday());
    }

    public function test_updates_conversation_increments_attempts(): void
    {
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'recovery_attempts' => 2,
            'last_recovery_at' => now()->subDays(2),
        ]);

        $this->service->updateConversationAfterRecovery($conversation);

        $conversation->refresh();

        $this->assertEquals(3, $conversation->recovery_attempts);
        $this->assertTrue($conversation->last_recovery_at->isToday());
    }

    public function test_updates_conversation_handles_zero_recovery_attempts(): void
    {
        // Use 0 instead of null since the column has NOT NULL constraint
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'recovery_attempts' => 0,
            'last_recovery_at' => null,
        ]);

        $this->service->updateConversationAfterRecovery($conversation);

        $conversation->refresh();

        $this->assertEquals(1, $conversation->recovery_attempts);
    }

    // =========================================================================
    // Test: getDefaultMessage
    // =========================================================================

    public function test_get_default_message_returns_thai_message(): void
    {
        $result = $this->service->getDefaultMessage();

        $expected = 'สวัสดีค่ะ ไม่ทราบว่ายังสนใจอยู่ไหมคะ? หากมีข้อสงสัยสามารถสอบถามได้เลยนะคะ';
        $this->assertEquals($expected, $result);
    }

    // =========================================================================
    // Test: Edge cases
    // =========================================================================

    public function test_find_eligible_uses_default_timeout_when_no_settings(): void
    {
        // Bot without settings - should use default 24 hour timeout
        // Conversation inactive for 25 hours
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 0,
            'last_message_at' => now()->subHours(25),
            'last_recovery_at' => null,
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(1, $result);
        $this->assertEquals($conversation->id, $result->first()->id);
    }

    public function test_find_eligible_uses_default_max_attempts_when_no_settings(): void
    {
        // Bot without settings - should use default 3 max attempts
        // Conversation with 3 attempts (at default max)
        Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 3,
            'last_message_at' => now()->subHours(25),
            'last_recovery_at' => null,
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(0, $result);
    }

    public function test_loads_customer_profile_with_eligible_conversations(): void
    {
        $this->createBotSettingsWithHITL($this->bot, true, 4, 3);

        // Create a customer profile with all required fields
        $customerProfile = CustomerProfile::create([
            'display_name' => 'Test Customer',
            'external_id' => 'U' . fake()->uuid(),
            'channel_type' => 'line',
        ]);

        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $customerProfile->id,
            'status' => 'active',
            'is_handover' => false,
            'recovery_attempts' => 0,
            'last_message_at' => now()->subHours(5),
            'last_recovery_at' => null,
        ]);

        $result = $this->service->findEligibleConversations($this->bot);

        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->relationLoaded('customerProfile'));
        $this->assertEquals($customerProfile->id, $result->first()->customerProfile->id);
    }
}
