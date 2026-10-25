<?php

namespace Tests\Feature\Chat;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\ConversationResponseLease;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use App\Services\MessageAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Wave 2 #4b: pending aggregation must be authoritative in the database, so a
 * Redis drop (cache flushed, connection errors) cannot lose a customer's
 * waiting messages or the reply they were promised.
 *
 * Cut-over safety: old-release jobs queued before the deploy still read their
 * cache-only group (read-through) and reply exactly once.
 */
class AggregationFailoverTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Bot $bot;

    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->active()->line()->create([
            'user_id' => $this->user->id,
        ]);
        $this->conversation = Conversation::factory()->line()->create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'U_test',
            'is_handover' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function userMessage(string $content): Message
    {
        return Message::factory()->fromUser()->create([
            'conversation_id' => $this->conversation->id,
            'content' => $content,
        ]);
    }

    private function startGroup(Message $message, int $waitMs = 1500): array
    {
        $group = app(MessageAggregationService::class)
            ->startOrContinueAggregation($this->conversation, $message, $waitMs);

        $this->assertNotNull($group, 'aggregation accepted the message');

        return $group;
    }

    private function aiReplyingOnce(?string $expectedContentPart = null): AIService&Mockery\MockInterface
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andReturnUsing(
            function ($bot, string $userMessage) use ($expectedContentPart) {
                if ($expectedContentPart !== null) {
                    $this->assertStringContainsString($expectedContentPart, $userMessage);
                }

                return [
                    'content' => 'Bot reply',
                    'model' => 'test/model',
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                    'cost' => 0.001,
                    'rag_metadata' => [],
                    'order_payload' => null,
                ];
            }
        );

        return $ai;
    }

    private function deliveryExpectingOnce(): AggregatedMessageDeliveryService&Mockery\MockInterface
    {
        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once();

        return $delivery;
    }

    private function silentAi(): AIService&Mockery\MockInterface
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->never();

        return $ai;
    }

    private function silentDelivery(): AggregatedMessageDeliveryService&Mockery\MockInterface
    {
        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->never();

        return $delivery;
    }

    private function runJob(
        string $groupId,
        AIService $ai,
        AggregatedMessageDeliveryService $delivery
    ): void {
        (new ProcessAggregatedMessages($this->bot, $this->conversation, $groupId, 'U_test'))
            ->handle(app(MessageAggregationService::class), $ai, $delivery);
    }

    private function botReplyCount(): int
    {
        return Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'bot')
            ->count();
    }

    private function lastPushedJob(): ProcessAggregatedMessages
    {
        $pushed = Queue::pushedJobs()[ProcessAggregatedMessages::class];
        $job = end($pushed)['job'];

        $this->assertInstanceOf(ProcessAggregatedMessages::class, $job);

        return $job;
    }

    private function holdLeaseFor(int $seconds): string
    {
        $lease = app(ConversationResponseLease::class);
        $token = $lease->acquire($this->conversation->id);
        $this->assertNotNull($token, 'holder acquires the response lease');

        if ($seconds !== ConversationResponseLease::TTL_SECONDS) {
            DB::table('conversation_response_leases')
                ->where('conversation_id', $this->conversation->id)
                ->update(['lease_until' => now()->addSeconds($seconds)]);
        }

        return $token;
    }

    /**
     * The core failure the card removes: Redis loses the cache while a group
     * is pending. 60 s later the delayed job fires — the group must still be
     * intact (DB-authoritative), the job must reply exactly once and clear
     * the group.
     */
    public function test_pending_group_survives_cache_loss_and_replies_once_after_60s(): void
    {
        $aggregation = app(MessageAggregationService::class);
        $group = $this->startGroup($this->userMessage('hello'), 1500);

        // Redis blip: everything in cache is gone.
        Cache::flush();

        // The database worker's 60 s idle sleep passes before the job runs.
        $this->travel(61)->seconds();

        $this->assertTrue(
            $aggregation->isActiveGroup($this->conversation->id, $group['group_id']),
            'group must still be active 60 s after the cache was flushed'
        );
        $this->assertSame(
            [$this->conversation->messages()->where('content', 'hello')->first()->id],
            $aggregation->getMessageIds($this->conversation->id),
        );

        $this->runJob($group['group_id'], $this->aiReplyingOnce('hello'), $this->deliveryExpectingOnce());

        $this->assertSame(1, $this->botReplyCount(), 'exactly one reply after cache loss');
        $this->assertFalse(
            $aggregation->isActiveGroup($this->conversation->id, $group['group_id']),
            'group must be cleared after the reply'
        );
        $this->assertNull(Cache::get("msg_agg:{$this->conversation->id}:group_id"));
    }

    /**
     * A newer group invalidates older delayed jobs (timer reset), including
     * after cache loss: the old job stays silent, the latest job replies once.
     */
    public function test_latest_group_wins_after_cache_loss_old_job_stays_silent(): void
    {
        $aggregation = app(MessageAggregationService::class);
        $first = $this->startGroup($this->userMessage('first'), 1500);
        $second = $this->startGroup($this->userMessage('second'), 1500);

        $this->assertNotSame($first['group_id'], $second['group_id'], 'new message resets the timer via a new group id');

        Cache::flush();
        $this->travel(61)->seconds();

        $this->runJob($first['group_id'], $this->silentAi(), $this->silentDelivery());
        $this->assertSame(0, $this->botReplyCount(), 'stale group must not reply');
        $this->assertTrue(
            $aggregation->isActiveGroup($this->conversation->id, $second['group_id']),
            'stale job must not clear the latest group'
        );

        $this->runJob($second['group_id'], $this->aiReplyingOnce('second'), $this->deliveryExpectingOnce());
        $this->assertSame(1, $this->botReplyCount(), 'latest group replies exactly once');
    }

    /**
     * Two workers race for one conversation: the DB-backed response lease
     * lets at most one reply through, and the loser schedules a re-check
     * instead of dropping the group.
     */
    public function test_two_concurrent_workers_produce_at_most_one_reply(): void
    {
        Queue::fake();

        $group = $this->startGroup($this->userMessage('hello'), 1500);

        // Worker A is mid-generation and holds the response lease.
        $lease = app(ConversationResponseLease::class);
        $token = $lease->acquire($this->conversation->id);
        $this->assertNotNull($token, 'worker A acquires the lease');

        // Worker B starts on the same group.
        $this->runJob($group['group_id'], $this->silentAi(), $this->silentDelivery());

        $this->assertSame(0, $this->botReplyCount(), 'lease holder wins; B must not reply');
        $this->assertTrue(
            app(MessageAggregationService::class)->isActiveGroup($this->conversation->id, $group['group_id']),
            'B must not clear the group while A is still working'
        );
        Queue::assertPushed(ProcessAggregatedMessages::class, 1);

        // A finishes: releases the lease, replies, clears the group.
        $lease->release($this->conversation->id, $token);

        $pushed = Queue::pushedJobs()[ProcessAggregatedMessages::class][0]['job'];
        $this->assertInstanceOf(ProcessAggregatedMessages::class, $pushed);
        $pushed->handle(
            app(MessageAggregationService::class),
            $this->aiReplyingOnce('hello'),
            $this->deliveryExpectingOnce()
        );

        $this->assertSame(1, $this->botReplyCount(), 'exactly one reply across both workers');
    }

    /**
     * If releasing the response lease explodes while the primary work also
     * failed, the ORIGINAL exception must surface — and no duplicate send
     * work may run.
     */
    public function test_release_failure_does_not_mask_primary_exception(): void
    {
        $group = $this->startGroup($this->userMessage('hello'), 1500);

        $leaseDouble = Mockery::mock(ConversationResponseLease::class)->makePartial();
        $leaseDouble->shouldReceive('release')->once()->andThrow(new RuntimeException('lease release exploded'));
        $this->app->instance(ConversationResponseLease::class, $leaseDouble);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andThrow(new RuntimeException('primary AI failure'));
        $delivery = $this->silentDelivery();

        try {
            $this->runJob($group['group_id'], $ai, $delivery);
            $this->fail('the primary exception must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('primary AI failure', $e->getMessage(), 'original exception must not be masked');
        }

        $this->assertSame(0, $this->botReplyCount(), 'a failed generation must not leave a half reply');
        $this->assertFalse(
            app(MessageAggregationService::class)->isActiveGroup($this->conversation->id, $group['group_id']),
            'group cleared on failure so a later message is not silently dropped'
        );
    }

    /**
     * Cut-over: an old-release job (cache-only group) drains through the new
     * release. The first new message adopts the cache group into the DB row;
     * the old delayed job stays silent (superseded) and exactly one reply
     * covering both messages goes out — even when the cache then dies.
     */
    public function test_cutover_old_cache_group_is_read_through_and_replies_exactly_once(): void
    {
        $aggregation = app(MessageAggregationService::class);
        $oldMessage = $this->userMessage('first');

        // Old release wrote group state ONLY to cache (pre-deploy shape).
        $oldGroupId = '00000000-0000-0000-0000-000000000001';
        Cache::put("msg_agg:{$this->conversation->id}:group_id", $oldGroupId, 300);
        Cache::put("msg_agg:{$this->conversation->id}:messages", [$oldMessage->id], 300);
        Cache::put("msg_agg:{$this->conversation->id}:started_at", now()->timestamp, 300);

        // Post-deploy message: must adopt the cache group, not drop it.
        $newGroup = $this->startGroup($this->userMessage('second'), 1500);
        $this->assertSame(
            [$oldMessage->id, $this->conversation->messages()->where('content', 'second')->first()->id],
            $aggregation->getMessageIds($this->conversation->id),
            'cache group must be adopted into the authoritative row'
        );

        // Redis dies during cut-over.
        Cache::flush();
        $this->travel(61)->seconds();

        $this->runJob($oldGroupId, $this->silentAi(), $this->silentDelivery());
        $this->assertSame(0, $this->botReplyCount(), 'superseded old job stays silent');

        $this->runJob($newGroup['group_id'], $this->aiReplyingOnce('second'), $this->deliveryExpectingOnce());
        $this->assertSame(1, $this->botReplyCount(), 'exactly one reply survives the cut-over + cache loss');
    }

    /**
     * With the group authoritative in the DB, cache ERRORS (not just empty
     * cache) must not break reads or the best-effort cleanup.
     */
    public function test_service_reads_authoritative_group_when_cache_throws(): void
    {
        $aggregation = app(MessageAggregationService::class);
        $group = $this->startGroup($this->userMessage('hello'), 1500);

        Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
        Cache::shouldReceive('forget')->andThrow(new RuntimeException('redis down'));

        $this->assertTrue($aggregation->isActiveGroup($this->conversation->id, $group['group_id']));
        $this->assertSame($group['group_id'], $aggregation->getCurrentGroupId($this->conversation->id));
        $this->assertSame(
            [$this->conversation->messages()->where('content', 'hello')->first()->id],
            $aggregation->getMessageIds($this->conversation->id)
        );
        $this->assertIsInt($aggregation->getStartedAt($this->conversation->id));

        // Best-effort cleanup must swallow cache errors.
        $aggregation->clearAggregation($this->conversation->id);
        $this->assertFalse($aggregation->isActiveGroup($this->conversation->id, $group['group_id']));
    }

    /**
     * The reply is already delivered when the realtime broadcast fires; a
     * broadcast failure (Redis down → queued broadcast cannot be pushed) must
     * not turn a delivered reply into a failed job.
     */
    public function test_broadcast_failure_after_delivery_does_not_fail_the_job(): void
    {
        $group = $this->startGroup($this->userMessage('hello'), 1500);

        Broadcast::shouldReceive('event')
            ->andThrow(new RuntimeException('redis down'));

        $this->runJob($group['group_id'], $this->aiReplyingOnce('hello'), $this->deliveryExpectingOnce());

        $this->assertSame(1, $this->botReplyCount(), 'reply survives broadcast failure');
        $this->assertFalse(
            app(MessageAggregationService::class)->isActiveGroup($this->conversation->id, $group['group_id']),
            'group still cleared after the reply'
        );
    }

    /**
     * The reply path must have no unwrapped cache reads left: the off-topic
     * breaker runs before the LLM call, so a Redis outage there would kill
     * the reply. Fail open (guardrail is cost-saving, not correctness).
     */
    public function test_off_topic_breaker_fails_open_when_cache_throws(): void
    {
        $breaker = new OffTopicCircuitBreaker;

        Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));

        $this->assertFalse(
            $breaker->isTripped($this->bot, $this->conversation),
            'breaker must fail open instead of throwing on cache errors'
        );
    }

    /**
     * Review round 1, blocking #1: the loser side must outlast the lease, not
     * the old 30 s lock. A holder may legitimately own the fence for up to
     * TTL_SECONDS = 210 s (long model time, or a stale lease after a hard
     * kill). The old 3×5 s budget deleted the group row on its 4th run while
     * the lease was still live — unsent work dropped. Now the waiter chain
     * (budget carried in the job payload) keeps the group until the fence
     * frees, then replies exactly once.
     */
    public function test_lease_held_beyond_old_lock_budget_group_survives_and_replies_once_after_release(): void
    {
        Queue::fake();

        $aggregation = app(MessageAggregationService::class);
        $group = $this->startGroup($this->userMessage('hello'), 1500);

        // A live holder well past the old 30 s lock: 90 s of remaining lease.
        $token = $this->holdLeaseFor(90);

        // Four loser runs, 6 s apart — the old budget (3 × 5 s) expired here
        // and cleared the group while nobody had replied.
        $waiters = [];
        $job = new ProcessAggregatedMessages($this->bot, $this->conversation, $group['group_id'], 'U_test');
        for ($run = 1; $run <= 4; $run++) {
            $this->travel(6)->seconds();
            $job->handle(app(MessageAggregationService::class), $this->silentAi(), $this->silentDelivery());

            $waiters[] = $job = $this->lastPushedJob();
        }

        $this->assertTrue(
            $aggregation->isActiveGroup($this->conversation->id, $group['group_id']),
            'unsent group must never be cleared while the lease is still live'
        );

        foreach ($waiters as $index => $waiter) {
            $this->assertSame(
                $index + 1,
                $waiter->leaseWaitAttempts,
                'the wait budget must live in the job payload, not in the (losable) cache'
            );
        }

        // The holder finishes (or a stale lease is cleared): the waiter
        // acquires the free fence and answers the group exactly once.
        app(ConversationResponseLease::class)->release($this->conversation->id, $token);

        $job->handle(
            app(MessageAggregationService::class),
            $this->aiReplyingOnce('hello'),
            $this->deliveryExpectingOnce()
        );

        $this->assertSame(1, $this->botReplyCount(), 'group replied exactly once after the holder released');
        $this->assertFalse($aggregation->isActiveGroup($this->conversation->id, $group['group_id']));
    }

    /**
     * Review round 1, blocking #2: Redis down AND the lease held — the
     * card's exact outage. The old waiter read its attempt counter from the
     * cache, got an exception and returned without re-dispatching: the group
     * row sat orphaned until the customer wrote again. The waiter is now
     * Redis-independent end to end (payload budget + DB fence), so it still
     * re-dispatches and the group is answered exactly once — with the cache
     * throwing on every single operation.
     */
    public function test_cache_down_and_lease_held_still_redispatches_and_replies_once(): void
    {
        Queue::fake();

        $aggregation = app(MessageAggregationService::class);
        $group = $this->startGroup($this->userMessage('hello'), 1500);

        // Lease held by a live holder while Redis goes down completely.
        $token = $this->holdLeaseFor(120);

        Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
        Cache::shouldReceive('put')->andThrow(new RuntimeException('redis down'));
        Cache::shouldReceive('forget')->andThrow(new RuntimeException('redis down'));
        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis down'));

        $this->runJob($group['group_id'], $this->silentAi(), $this->silentDelivery());

        Queue::assertPushed(ProcessAggregatedMessages::class, 1);
        $this->assertSame(
            1,
            $this->lastPushedJob()->leaseWaitAttempts,
            'the re-dispatch must happen without any readable cache'
        );
        $this->assertTrue(
            $aggregation->isActiveGroup($this->conversation->id, $group['group_id']),
            'group row kept while the waiter is parked'
        );

        // The holder finishes while Redis is still down; the parked waiter
        // picks the group up through the DB alone and replies once.
        app(ConversationResponseLease::class)->release($this->conversation->id, $token);

        $this->lastPushedJob()->handle(
            app(MessageAggregationService::class),
            $this->aiReplyingOnce('hello'),
            $this->deliveryExpectingOnce()
        );

        $this->assertSame(1, $this->botReplyCount(), 'outage group replied exactly once');
        $this->assertFalse($aggregation->isActiveGroup($this->conversation->id, $group['group_id']));
    }

    /**
     * Review round 1, non-blocking: recordTrigger() runs AFTER the LLM call.
     * A cache error there must fail open (the trip counter is a cost guard,
     * not reply correctness) and leave a log line instead of killing the job.
     */
    public function test_off_topic_breaker_record_trigger_fails_open_when_cache_throws(): void
    {
        $breaker = new OffTopicCircuitBreaker;

        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis down'));
        $logged = false;
        Log::shouldReceive('warning')->andReturnUsing(function (string $message) use (&$logged): void {
            $logged = str_contains($message, 'Off-topic breaker');
        });

        $breaker->recordTrigger($this->bot, $this->conversation);

        $this->assertTrue($logged, 'recordTrigger must log a warning when the cache throws');
    }
}
