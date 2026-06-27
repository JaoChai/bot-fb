<?php

namespace Tests\Unit\Services\LineWebhook;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Services\AutoAssignmentService;
use App\Services\LINEService;
use App\Services\LineWebhook\GateDecision;
use App\Services\LineWebhook\LineWebhookContextService;
use App\Services\LineWebhook\WebhookContext;
use App\Services\MessageAggregationService;
use App\Services\ProfilePictureService;
use App\Services\RateLimitService;
use App\Services\ResponseHoursService;
use App\Services\SmartAggregation\AggregationContext;
use App\Services\SmartAggregation\SmartAggregationAnalyzer;
use App\Services\SmartAggregation\UserTypingStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class LineWebhookContextServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEvent(string $userId = 'U_test', string $text = 'hello', string $msgId = 'msg_001', ?string $webhookEventId = 'evt_001'): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'rt_test',
            'source' => ['userId' => $userId],
            'message' => ['type' => 'text', 'text' => $text, 'id' => $msgId],
            'webhookEventId' => $webhookEventId,
            'timestamp' => 1700000000000,
            'deliveryContext' => ['isRedelivery' => false],
        ];
    }

    private function makeService(
        ?LINEService $line = null,
        ?RateLimitService $rateLimit = null,
        ?ResponseHoursService $responseHours = null,
        ?MessageAggregationService $aggregation = null,
        ?SmartAggregationAnalyzer $smartAnalyzer = null,
        ?UserTypingStats $userTypingStats = null,
        ?ProfilePictureService $profilePicture = null,
        ?AutoAssignmentService $autoAssignment = null,
    ): LineWebhookContextService {
        $line ??= $this->mockLineServiceAllowed();
        if ($rateLimit === null) {
            $rateLimit = Mockery::mock(RateLimitService::class);
            $rateLimit->shouldReceive('incrementCounters')->andReturn(null)->byDefault();
        }
        $responseHours ??= $this->mockResponseHoursAllowed();
        $aggregation ??= $this->mockAggregationDisabled();
        $smartAnalyzer ??= Mockery::mock(SmartAggregationAnalyzer::class);
        $userTypingStats ??= Mockery::mock(UserTypingStats::class);
        $profilePicture ??= $this->mockProfilePicture();
        $autoAssignment ??= $this->mockAutoAssignmentNoAssign();

        return new LineWebhookContextService(
            $line,
            $rateLimit,
            $responseHours,
            $aggregation,
            $smartAnalyzer,
            $userTypingStats,
            $profilePicture,
            $autoAssignment,
        );
    }

    private function mockLineServiceAllowed(): LINEService
    {
        $mock = Mockery::mock(LINEService::class);
        $mock->shouldReceive('showLoadingIndicator')->andReturn(true)->byDefault();
        $mock->shouldReceive('getProfile')->andReturn(['displayName' => 'Test User', 'pictureUrl' => null])->byDefault();
        $mock->shouldReceive('generateRetryKey')->andReturn('rk_test')->byDefault();
        $mock->shouldReceive('replyWithFallback')->andReturn(null)->byDefault();

        return $mock;
    }

    private function mockResponseHoursAllowed(): ResponseHoursService
    {
        $mock = Mockery::mock(ResponseHoursService::class);
        $mock->shouldReceive('checkResponseHours')->andReturn(['allowed' => true])->byDefault();

        return $mock;
    }

    private function mockResponseHoursBlocked(ResponseHoursService $mock): void
    {
        $mock->shouldReceive('checkResponseHours')->andReturn(['allowed' => false])->byDefault();
        $mock->shouldReceive('getOfflineMessage')->andReturn('We are closed')->byDefault();
    }

    private function mockAggregationDisabled(): MessageAggregationService
    {
        $mock = Mockery::mock(MessageAggregationService::class);
        $mock->shouldReceive('isEnabled')->andReturn(false)->byDefault();
        $mock->shouldReceive('getWaitTimeMs')->andReturn(0)->byDefault();

        return $mock;
    }

    private function mockProfilePicture(): ProfilePictureService
    {
        $mock = Mockery::mock(ProfilePictureService::class);
        $mock->shouldReceive('downloadAndStore')->andReturn(null)->byDefault();

        return $mock;
    }

    private function mockAutoAssignmentNoAssign(): AutoAssignmentService
    {
        $mock = Mockery::mock(AutoAssignmentService::class);
        $mock->shouldReceive('assignConversation')->andReturn(null)->byDefault();

        return $mock;
    }

    private function makeBot(array $overrides = []): Bot
    {
        return Bot::factory()->active()->line()->create(array_merge([
            'auto_handover' => false,
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Test 1: New customer + new conversation + message saved → ALLOW
    // -------------------------------------------------------------------------

    public function test_new_customer_new_conversation_message_saved_gate_allow(): void
    {
        Queue::fake();

        $bot = $this->makeBot();
        $ctx = new WebhookContext($bot, $this->makeEvent());

        $svc = $this->makeService();
        $svc->resolve($ctx);

        // Profile created
        $this->assertNotNull($ctx->profile);
        $this->assertDatabaseHas('customer_profiles', [
            'external_id' => 'U_test',
            'channel_type' => 'line',
        ]);

        // Conversation created
        $this->assertNotNull($ctx->conversation);
        $this->assertDatabaseHas('conversations', [
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_test',
            'channel_type' => 'line',
        ]);

        // Message saved
        $this->assertNotNull($ctx->userMessage);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $ctx->conversation->id,
            'sender' => 'user',
            'content' => 'hello',
        ]);

        // Gate: ALLOW (null = not blocked by context service; Stage 3 proceeds)
        $this->assertNull($ctx->gateDecision);
        $this->assertFalse($ctx->aggregationBuffered);
    }

    // -------------------------------------------------------------------------
    // Test 2: Existing customer + existing conversation → no duplicate profile
    // -------------------------------------------------------------------------

    public function test_existing_customer_existing_conversation_no_duplicate_profile(): void
    {
        Queue::fake();

        $bot = $this->makeBot();

        $profile = CustomerProfile::factory()->create([
            'external_id' => 'U_existing',
            'channel_type' => 'line',
        ]);

        $conversation = Conversation::factory()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_existing',
            'channel_type' => 'line',
            'status' => 'active',
            'is_handover' => false,
            'customer_profile_id' => $profile->id,
        ]);

        $ctx = new WebhookContext($bot, $this->makeEvent('U_existing', 'second message', 'msg_002', 'evt_002'));

        $svc = $this->makeService();
        $svc->resolve($ctx);

        // No duplicate profile row
        $this->assertSame(1, CustomerProfile::where('external_id', 'U_existing')->where('channel_type', 'line')->count());

        // Conversation reused (same id)
        $this->assertSame($conversation->id, $ctx->conversation->id);

        // Message saved
        $this->assertNotNull($ctx->userMessage);
    }

    // -------------------------------------------------------------------------
    // Test 3: Outside hours + not in handover → offline message sent, OUTSIDE_HOURS, no new message
    // -------------------------------------------------------------------------

    public function test_outside_hours_not_in_handover_sends_offline_and_gates(): void
    {
        $bot = $this->makeBot();
        $ctx = new WebhookContext($bot, $this->makeEvent());

        $responseHours = Mockery::mock(ResponseHoursService::class);
        $this->mockResponseHoursBlocked($responseHours);

        $line = $this->mockLineServiceAllowed();
        $line->shouldReceive('replyWithFallback')->once();

        $svc = $this->makeService(line: $line, responseHours: $responseHours);
        $svc->resolve($ctx);

        $this->assertSame(GateDecision::OUTSIDE_HOURS, $ctx->gateDecision);
        $this->assertNull($ctx->userMessage);
        $this->assertDatabaseMissing('messages', ['sender' => 'user', 'content' => 'hello']);
    }

    // -------------------------------------------------------------------------
    // Test 4: Outside hours + conversation in handover → no offline message, OUTSIDE_HOURS
    // -------------------------------------------------------------------------

    public function test_outside_hours_in_handover_no_offline_message(): void
    {
        $bot = $this->makeBot();

        // Create an existing handover conversation
        $profile = CustomerProfile::factory()->create([
            'external_id' => 'U_test',
            'channel_type' => 'line',
        ]);
        Conversation::factory()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_test',
            'channel_type' => 'line',
            'status' => 'handover',
            'is_handover' => true,
            'customer_profile_id' => $profile->id,
        ]);

        $ctx = new WebhookContext($bot, $this->makeEvent());

        $responseHours = Mockery::mock(ResponseHoursService::class);
        $this->mockResponseHoursBlocked($responseHours);

        $line = $this->mockLineServiceAllowed();
        $line->shouldNotReceive('replyWithFallback');

        $svc = $this->makeService(line: $line, responseHours: $responseHours);
        $svc->resolve($ctx);

        $this->assertSame(GateDecision::OUTSIDE_HOURS, $ctx->gateDecision);
        $this->assertNull($ctx->userMessage);
    }

    // -------------------------------------------------------------------------
    // Test 5: Aggregation enabled + first message → aggregationBuffered = true
    // -------------------------------------------------------------------------

    public function test_aggregation_enabled_first_message_buffers(): void
    {
        Queue::fake();

        $bot = $this->makeBot();
        $ctx = new WebhookContext($bot, $this->makeEvent());

        $aggregation = Mockery::mock(MessageAggregationService::class);
        $aggregation->shouldReceive('isEnabled')->andReturn(true);
        $aggregation->shouldReceive('getWaitTimeMs')->andReturn(3000);
        $fakeContext = Mockery::mock(AggregationContext::class)->makePartial();
        $aggregation->shouldReceive('buildContext')->andReturn($fakeContext);
        $aggregation->shouldReceive('startOrContinueAggregation')->andReturn([
            'group_id' => 'grp_001',
            'is_new_group' => true,
            'message_count' => 1,
        ]);

        $smartAnalyzer = Mockery::mock(SmartAggregationAnalyzer::class);
        $smartAnalyzer->shouldReceive('isSmartEnabled')->andReturn(false);
        $smartAnalyzer->shouldReceive('shouldTriggerEarly')->andReturn(false)->byDefault();

        $svc = $this->makeService(aggregation: $aggregation, smartAnalyzer: $smartAnalyzer);
        $svc->resolve($ctx);

        $this->assertTrue($ctx->aggregationBuffered);
        $this->assertNotNull($ctx->userMessage);
        $this->assertArrayHasKey('aggregation_group_id', $ctx->metadata);
        $this->assertSame('grp_001', $ctx->metadata['aggregation_group_id']);

        Queue::assertPushed(ProcessAggregatedMessages::class);
    }

    // -------------------------------------------------------------------------
    // Test 6: Bot inactive → message saved, bot_inactive metadata, stats updated
    // -------------------------------------------------------------------------

    public function test_bot_inactive_message_saved_with_metadata(): void
    {
        Queue::fake();

        $bot = $this->makeBot(['status' => 'inactive']);
        $ctx = new WebhookContext($bot, $this->makeEvent());

        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('incrementCounters')->once();

        $svc = $this->makeService(rateLimit: $rateLimit);
        $svc->resolve($ctx);

        $this->assertNotNull($ctx->userMessage);
        $this->assertTrue($ctx->metadata['bot_inactive'] ?? false);
        $this->assertDatabaseHas('messages', [
            'sender' => 'user',
            'content' => 'hello',
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 7a: userId null → resolve() is a no-op (no DB writes, no service calls)
    // -------------------------------------------------------------------------

    public function test_resolve_returns_early_when_user_id_is_null(): void
    {
        Queue::fake();

        $bot = $this->makeBot();

        // Event with no userId (source has no userId key)
        $emptyEvent = [];
        $ctx = new WebhookContext($bot, $emptyEvent);

        $line = Mockery::mock(LINEService::class);
        $line->shouldNotReceive('showLoadingIndicator');
        $line->shouldNotReceive('getProfile');

        $responseHours = Mockery::mock(ResponseHoursService::class);
        $responseHours->shouldNotReceive('checkResponseHours');

        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldNotReceive('incrementCounters');

        $svc = $this->makeService(line: $line, rateLimit: $rateLimit, responseHours: $responseHours);
        $svc->resolve($ctx);

        // No DB writes
        $this->assertDatabaseMissing('messages', ['sender' => 'user']);
        $this->assertDatabaseMissing('conversations', ['bot_id' => $bot->id]);

        // Context untouched
        $this->assertNull($ctx->gateDecision);
        $this->assertNull($ctx->userMessage);
        $this->assertNull($ctx->conversation);
        $this->assertFalse($ctx->aggregationBuffered);
    }

    // -------------------------------------------------------------------------
    // Test 7: Conversation in handover → message saved, handover metadata
    // -------------------------------------------------------------------------

    public function test_conversation_in_handover_message_saved_with_metadata(): void
    {
        Queue::fake();

        $bot = $this->makeBot();

        $profile = CustomerProfile::factory()->create([
            'external_id' => 'U_test',
            'channel_type' => 'line',
        ]);
        Conversation::factory()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_test',
            'channel_type' => 'line',
            'status' => 'handover',
            'is_handover' => true,
            'customer_profile_id' => $profile->id,
        ]);

        $ctx = new WebhookContext($bot, $this->makeEvent());

        $rateLimit = Mockery::mock(RateLimitService::class);
        $rateLimit->shouldReceive('incrementCounters')->once();

        $svc = $this->makeService(rateLimit: $rateLimit);
        $svc->resolve($ctx);

        $this->assertNotNull($ctx->userMessage);
        $this->assertTrue($ctx->metadata['handover'] ?? false);
        $this->assertDatabaseHas('messages', [
            'sender' => 'user',
            'content' => 'hello',
        ]);
    }
}
