<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotHITLSettings;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\LeadRecoveryLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'owner']);
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Helper method to create a LeadRecoveryLog.
     */
    protected function createLeadRecoveryLog(array $attributes = []): LeadRecoveryLog
    {
        $conversation = $attributes['conversation'] ?? Conversation::factory()->create([
            'bot_id' => $this->bot->id,
        ]);

        return LeadRecoveryLog::create(array_merge([
            'conversation_id' => $conversation->id,
            'bot_id' => $this->bot->id,
            'attempt_number' => 1,
            'message_mode' => 'static',
            'message_sent' => 'Hello! Are you still interested?',
            'sent_at' => now(),
            'delivery_status' => 'sent',
            'customer_responded' => false,
            'responded_at' => null,
        ], $attributes));
    }

    /**
     * Helper method to create a CustomerProfile.
     */
    protected function createCustomerProfile(array $attributes = []): CustomerProfile
    {
        return CustomerProfile::create(array_merge([
            'external_id' => 'U'.fake()->uuid(),
            'channel_type' => 'line',
            'display_name' => fake()->name(),
            'picture_url' => fake()->imageUrl(),
            'interaction_count' => fake()->numberBetween(1, 100),
            'first_interaction_at' => now()->subDays(30),
            'last_interaction_at' => now(),
        ], $attributes));
    }

    // ===== Stats Endpoint Tests =====

    public function test_can_get_lead_recovery_stats(): void
    {
        // Create some LeadRecoveryLogs
        $this->createLeadRecoveryLog(['customer_responded' => true, 'responded_at' => now()]);
        $this->createLeadRecoveryLog(['customer_responded' => true, 'responded_at' => now()]);
        $this->createLeadRecoveryLog(['customer_responded' => false]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_sent',
                    'total_responded',
                    'response_rate',
                    'by_mode',
                    'daily_breakdown',
                    'period' => ['type', 'start', 'end'],
                ],
            ])
            ->assertJsonPath('data.total_sent', 3)
            ->assertJsonPath('data.total_responded', 2)
            ->assertJsonPath('data.response_rate', 66.67);
    }

    public function test_stats_returns_zero_when_no_logs(): void
    {
        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats");

        $response->assertOk()
            ->assertJsonPath('data.total_sent', 0)
            ->assertJsonPath('data.total_responded', 0)
            ->assertJsonPath('data.response_rate', 0);
    }

    public function test_stats_filters_by_day_period(): void
    {
        // Create logs from today
        $this->createLeadRecoveryLog(['sent_at' => now()]);
        $this->createLeadRecoveryLog(['sent_at' => now()]);

        // Create logs from 3 days ago (outside 'day' period)
        $this->createLeadRecoveryLog(['sent_at' => now()->subDays(3)]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats?period=day");

        $response->assertOk()
            ->assertJsonPath('data.total_sent', 2)
            ->assertJsonPath('data.period.type', 'day');
    }

    public function test_stats_filters_by_week_period(): void
    {
        // Create logs from this week
        $this->createLeadRecoveryLog(['sent_at' => now()]);
        $this->createLeadRecoveryLog(['sent_at' => now()->subDays(3)]);
        $this->createLeadRecoveryLog(['sent_at' => now()->subDays(6)]);

        // Create log from 10 days ago (outside 'week' period)
        $this->createLeadRecoveryLog(['sent_at' => now()->subDays(10)]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats?period=week");

        $response->assertOk()
            ->assertJsonPath('data.total_sent', 3)
            ->assertJsonPath('data.period.type', 'week');
    }

    public function test_stats_filters_by_month_period(): void
    {
        // Create logs from this month
        $this->createLeadRecoveryLog(['sent_at' => now()]);
        $this->createLeadRecoveryLog(['sent_at' => now()->subDays(15)]);
        $this->createLeadRecoveryLog(['sent_at' => now()->subDays(25)]);

        // Create log from 35 days ago (outside 'month' period which is 30 days)
        $this->createLeadRecoveryLog(['sent_at' => now()->subDays(35)]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats?period=month");

        $response->assertOk()
            ->assertJsonPath('data.total_sent', 3)
            ->assertJsonPath('data.period.type', 'month');
    }

    public function test_stats_breakdown_by_mode(): void
    {
        // Create static mode logs
        $this->createLeadRecoveryLog(['message_mode' => 'static', 'customer_responded' => true, 'responded_at' => now()]);
        $this->createLeadRecoveryLog(['message_mode' => 'static', 'customer_responded' => false]);

        // Create AI mode logs
        $this->createLeadRecoveryLog(['message_mode' => 'ai', 'customer_responded' => true, 'responded_at' => now()]);
        $this->createLeadRecoveryLog(['message_mode' => 'ai', 'customer_responded' => true, 'responded_at' => now()]);
        $this->createLeadRecoveryLog(['message_mode' => 'ai', 'customer_responded' => false]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats");

        $response->assertOk();

        $data = $response->json('data');

        // Verify static mode stats
        $this->assertEquals(2, $data['by_mode']['static']['sent']);
        $this->assertEquals(1, $data['by_mode']['static']['responded']);
        $this->assertEquals(50.0, $data['by_mode']['static']['response_rate']);

        // Verify AI mode stats
        $this->assertEquals(3, $data['by_mode']['ai']['sent']);
        $this->assertEquals(2, $data['by_mode']['ai']['responded']);
        $this->assertEquals(66.67, $data['by_mode']['ai']['response_rate']);
    }

    // ===== Logs Endpoint Tests =====

    public function test_can_get_lead_recovery_logs(): void
    {
        // Create some logs
        $this->createLeadRecoveryLog();
        $this->createLeadRecoveryLog();
        $this->createLeadRecoveryLog();

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'conversation_id',
                        'attempt_number',
                        'message_mode',
                        'message_sent',
                        'sent_at',
                        'delivery_status',
                        'customer_responded',
                        'responded_at',
                        'customer',
                    ],
                ],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_logs_are_paginated(): void
    {
        // Create 25 logs
        for ($i = 0; $i < 25; $i++) {
            $this->createLeadRecoveryLog(['sent_at' => now()->subMinutes($i)]);
        }

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs?per_page=10");

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_logs_includes_customer_info(): void
    {
        // Create a customer profile
        $customerProfile = $this->createCustomerProfile([
            'display_name' => 'John Doe',
            'external_id' => 'U123456789',
        ]);

        // Create conversation with customer profile
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => $customerProfile->id,
        ]);

        // Create log with the conversation
        $this->createLeadRecoveryLog(['conversation' => $conversation]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

        $response->assertOk();

        $log = $response->json('data.0');
        $this->assertNotNull($log['customer']);
        $this->assertEquals('John Doe', $log['customer']['name']);
        $this->assertEquals('U123456789', $log['customer']['external_id']);
    }

    public function test_logs_handles_null_customer_profile(): void
    {
        // Create conversation without customer profile
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'customer_profile_id' => null,
        ]);

        $this->createLeadRecoveryLog(['conversation' => $conversation]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

        $response->assertOk();

        $log = $response->json('data.0');
        $this->assertNull($log['customer']);
    }

    public function test_logs_ordered_by_sent_at_descending(): void
    {
        $this->createLeadRecoveryLog(['sent_at' => now()->subHours(2)]);
        $this->createLeadRecoveryLog(['sent_at' => now()->subHours(1)]);
        $this->createLeadRecoveryLog(['sent_at' => now()]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

        $response->assertOk();

        $logs = $response->json('data');
        $this->assertCount(3, $logs);

        // Most recent first
        $sentTimes = array_map(fn ($log) => Carbon::parse($log['sent_at'])->timestamp, $logs);
        $sortedTimes = $sentTimes;
        rsort($sortedTimes);
        $this->assertEquals($sortedTimes, $sentTimes);
    }

    // ===== Authorization Tests =====

    public function test_unauthorized_user_cannot_access_stats(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats");

        $response->assertForbidden();
    }

    public function test_unauthorized_user_cannot_access_logs(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_access_stats(): void
    {
        $response = $this->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats");

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_access_logs(): void
    {
        $response = $this->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

        $response->assertUnauthorized();
    }

    public function test_stats_returns_404_for_nonexistent_bot(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/bots/99999/lead-recovery/stats');

        $response->assertNotFound();
    }

    public function test_logs_returns_404_for_nonexistent_bot(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/bots/99999/lead-recovery/logs');

        $response->assertNotFound();
    }

    // ===== Settings Update Tests =====

    public function test_can_update_lead_recovery_settings(): void
    {
        // Create bot settings first
        $botSetting = BotSetting::create([
            'bot_id' => $this->bot->id,
            'daily_message_limit' => 1000,
            'per_user_limit' => 100,
            'rate_limit_per_minute' => 20,
            'max_tokens_per_response' => 2000,
        ]);

        // Create HITL settings
        BotHITLSettings::create([
            'bot_setting_id' => $botSetting->id,
            'hitl_enabled' => false,
            'lead_recovery_enabled' => false,
            'lead_recovery_timeout_hours' => 4,
            'lead_recovery_mode' => 'static',
            'lead_recovery_message' => null,
            'lead_recovery_max_attempts' => 2,
        ]);

        // Update via PATCH endpoint
        $response = $this->actingAs($this->user)->patchJson("/api/bots/{$this->bot->id}/settings", [
            'hitl_enabled' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.hitl_enabled', true);
    }

    // ===== Validation Tests =====

    public function test_stats_validates_period_parameter(): void
    {
        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats?period=invalid");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['period']);
    }

    public function test_logs_validates_per_page_parameter(): void
    {
        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs?per_page=500");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_logs_validates_page_parameter(): void
    {
        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs?page=0");

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);
    }

    // ===== Edge Cases =====

    public function test_stats_only_returns_logs_for_requested_bot(): void
    {
        // Create logs for our bot
        $this->createLeadRecoveryLog();
        $this->createLeadRecoveryLog();

        // Create logs for another bot
        $otherBot = Bot::factory()->create(['user_id' => $this->user->id]);
        $otherConversation = Conversation::factory()->create(['bot_id' => $otherBot->id]);
        LeadRecoveryLog::create([
            'conversation_id' => $otherConversation->id,
            'bot_id' => $otherBot->id,
            'attempt_number' => 1,
            'message_mode' => 'static',
            'message_sent' => 'Test',
            'sent_at' => now(),
            'delivery_status' => 'sent',
            'customer_responded' => false,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats");

        $response->assertOk()
            ->assertJsonPath('data.total_sent', 2);
    }

    public function test_logs_only_returns_logs_for_requested_bot(): void
    {
        // Create logs for our bot
        $this->createLeadRecoveryLog();
        $this->createLeadRecoveryLog();

        // Create logs for another bot
        $otherBot = Bot::factory()->create(['user_id' => $this->user->id]);
        $otherConversation = Conversation::factory()->create(['bot_id' => $otherBot->id]);
        LeadRecoveryLog::create([
            'conversation_id' => $otherConversation->id,
            'bot_id' => $otherBot->id,
            'attempt_number' => 1,
            'message_mode' => 'static',
            'message_sent' => 'Test',
            'sent_at' => now(),
            'delivery_status' => 'sent',
            'customer_responded' => false,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ===== B5b: reports count and list sent rows only (D59/D68) =====

    private const B5B_NOW = '2026-11-16 10:00:00';

    /** เวลาทดสอบคงที่ — เลี่ยงสับสนขอบ period/วันที่รายวันกับเวลาจริง */
    private function freezeB5bNow(): void
    {
        Carbon::setTestNow(Carbon::parse(self::B5B_NOW, 'Asia/Bangkok'));
    }

    /** สองแถว sent (ส่ง 2 ชม. และ 1 ชม. ก่อน) — พื้นฐานร่วมของเคส logs/stats */
    private function createB5bSentBaseline(): array
    {
        $this->freezeB5bNow();

        $older = $this->createLeadRecoveryLog([
            'sent_at' => now()->subHours(2),
            'message_mode' => 'static',
            'customer_responded' => true,
            'responded_at' => now()->subMinutes(30),
        ]);
        $newer = $this->createLeadRecoveryLog([
            'sent_at' => now()->subHours(1),
            'message_mode' => 'ai',
            'customer_responded' => false,
        ]);

        return [$older, $newer];
    }

    public function test_logs_baseline_lists_two_sent_rows_newer_first(): void
    {
        [$older, $newer] = $this->createB5bSentBaseline();

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 1);
    }

    /**
     * พื้นฐาน 2 แถว sent + แถวเพิ่ม "ตัวเดียว" ตามชุดข้อมูล — แถวที่ไม่ใช่ sent
     * (หรือ sent แต่ sent_at null) ต้องไม่ถูกนับและไม่ถูกแสดงเลย
     */
    #[DataProvider('extraUnlistedLogRowProvider')]
    public function test_logs_ignores_one_extra_row(string $status, bool $withSentAt, bool $responded): void
    {
        [$older, $newer] = $this->createB5bSentBaseline();
        $this->createLeadRecoveryLog([
            'delivery_status' => $status,
            'sent_at' => $withSentAt ? now() : null,
            'customer_responded' => $responded,
            'responded_at' => $responded ? now() : null,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 1);
    }

    public static function extraUnlistedLogRowProvider(): array
    {
        $sets = [];

        foreach ([
            'candidate', 'sorting', 'excluded', 'sort_retry', 'deferred', 'drafting',
            'pending', 'approved', 'sending', 'retry', 'send_failed', 'skipped',
            'expired', 'cancelled',
        ] as $status) {
            $sets["pipeline_row_{$status}_with_sent_at_null"] = [$status, false, false];
        }

        $sets['send_failed_row_with_sent_at_set'] = ['send_failed', true, true];
        $sets['sent_row_with_sent_at_null'] = ['sent', false, false];

        return $sets;
    }

    public function test_logs_same_sent_at_orders_higher_id_first_and_pages_split_the_rows(): void
    {
        $this->freezeB5bNow();
        $earlier = $this->createLeadRecoveryLog(['sent_at' => Carbon::parse('2026-11-16 08:00:00')]);
        $later = $this->createLeadRecoveryLog(['sent_at' => Carbon::parse('2026-11-16 08:00:00')]);
        $this->assertGreaterThan($earlier->id, $later->id, 'precondition: the second insert holds the higher id');

        $page1 = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs?per_page=1&page=1");
        $page2 = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs?per_page=1&page=2");

        $page1->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $later->id);
        $page2->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $earlier->id);
    }

    public function test_stats_baseline_two_sent_rows_one_responded(): void
    {
        $this->createB5bSentBaseline();

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats");

        $response->assertOk()
            ->assertJsonPath('data.total_sent', 2)
            ->assertJsonPath('data.total_responded', 1)
            ->assertJsonPath('data.response_rate', 50)
            ->assertJsonPath('data.by_mode.static.sent', 1)
            ->assertJsonPath('data.by_mode.static.responded', 1)
            ->assertJsonPath('data.by_mode.static.response_rate', 100)
            ->assertJsonPath('data.by_mode.ai.sent', 1)
            ->assertJsonPath('data.by_mode.ai.responded', 0)
            ->assertJsonPath('data.by_mode.ai.response_rate', 0)
            ->assertJsonPath('data.daily_breakdown.2026-11-16.sent', 2)
            ->assertJsonPath('data.daily_breakdown.2026-11-16.responded', 1);
    }

    /**
     * พื้นฐานสถิติ + แถวเพิ่ม "ตัวเดียว" — ทุกตัวเลขต้องคงเดิมทั้งหมด
     */
    #[DataProvider('extraIgnoredStatsRowProvider')]
    public function test_stats_ignores_one_extra_row(string $status, bool $withSentAt, bool $responded): void
    {
        $this->createB5bSentBaseline();
        $this->createLeadRecoveryLog([
            'delivery_status' => $status,
            'sent_at' => $withSentAt ? now() : null,
            'customer_responded' => $responded,
            'responded_at' => $responded ? now() : null,
        ]);

        $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats");

        $response->assertOk()
            ->assertJsonPath('data.total_sent', 2)
            ->assertJsonPath('data.total_responded', 1)
            ->assertJsonPath('data.response_rate', 50)
            ->assertJsonPath('data.by_mode.static.sent', 1)
            ->assertJsonPath('data.by_mode.static.responded', 1)
            ->assertJsonPath('data.by_mode.static.response_rate', 100)
            ->assertJsonPath('data.by_mode.ai.sent', 1)
            ->assertJsonPath('data.by_mode.ai.responded', 0)
            ->assertJsonPath('data.by_mode.ai.response_rate', 0)
            ->assertJsonPath('data.daily_breakdown.2026-11-16.sent', 2)
            ->assertJsonPath('data.daily_breakdown.2026-11-16.responded', 1);
    }

    public static function extraIgnoredStatsRowProvider(): array
    {
        return [
            'send_failed_row_with_sent_at_set_and_responded_true' => ['send_failed', true, true],
            'retry_row_with_sent_at_set' => ['retry', true, false],
            'sent_row_with_sent_at_null' => ['sent', false, false],
        ];
    }

    public function test_stats_internal_failure_returns_fixed_body_and_canary_stays_in_log(): void
    {
        // เปลี่ยนชื่อตารางเพื่อบังคับให้ query throw — sqlite รายงาน "no such table: lead_recovery_logs"
        // (ชื่อตารางที่ query หา) — ข้อความ exception นี้คือ canary: ต้องอยู่ใน Log::error เท่านั้น
        $canary = 'no such table: lead_recovery_logs';
        Schema::rename('lead_recovery_logs', 'b5b_stats_hidden_table');

        try {
            Log::shouldReceive('error')->atLeast()->once()->withArgs(
                function ($message, $context) use ($canary) {
                    return str_contains(json_encode([$message, $context]), $canary);
                }
            );

            $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/stats");

            $response->assertStatus(500);
            $this->assertSame(['error' => 'Internal server error'], $response->json(), '500 body must be the fixed message only');
            $this->assertStringNotContainsString($canary, $response->getContent(), 'the exception message must not leak into the body');
            $this->assertStringNotContainsString('Connection.php', $response->getContent(), 'the exception file must not leak into the body');
        } finally {
            Schema::rename('b5b_stats_hidden_table', 'lead_recovery_logs');
        }
    }

    public function test_logs_internal_failure_returns_fixed_body_and_canary_stays_in_log(): void
    {
        // เปลี่ยนชื่อตารางเพื่อบังคับให้ query throw — ข้อความ exception คือ canary: ต้องอยู่ใน Log::error เท่านั้น
        $canary = 'no such table: lead_recovery_logs';
        Schema::rename('lead_recovery_logs', 'b5b_logs_hidden_table');

        try {
            Log::shouldReceive('error')->atLeast()->once()->withArgs(
                function ($message, $context) use ($canary) {
                    return str_contains(json_encode([$message, $context]), $canary);
                }
            );

            $response = $this->actingAs($this->user)->getJson("/api/bots/{$this->bot->id}/lead-recovery/logs");

            $response->assertStatus(500);
            $this->assertSame(['error' => 'Internal server error'], $response->json(), '500 body must be the fixed message only');
            $this->assertStringNotContainsString($canary, $response->getContent(), 'the exception message must not leak into the body');
            $this->assertStringNotContainsString('Connection.php', $response->getContent(), 'the exception file must not leak into the body');
        } finally {
            Schema::rename('b5b_logs_hidden_table', 'lead_recovery_logs');
        }
    }
}
