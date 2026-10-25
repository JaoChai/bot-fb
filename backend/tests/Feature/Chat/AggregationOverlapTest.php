<?php

namespace Tests\Feature\Chat;

use App\Jobs\ProcessAggregatedMessages;
use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAggregationGroup;
use App\Models\User;
use App\Services\AIService;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\MessageAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * X1: a customer message that arrives WHILE the bot is still generating its
 * reply to the previous message must get its own answer exactly once after
 * that reply finishes — and its AI input must contain only the new message.
 *
 * QA timeline reproduced (card t_70702f4f): ids=[13] → [13,14] (new group)
 * → row deleted by the holder's clearAggregation() → 14 never answered.
 */
class AggregationOverlapTest extends TestCase
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
            'external_customer_id' => 'U_overlap',
            'is_handover' => false,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function userMessage(string $content): Message
    {
        return Message::factory()->fromUser()->create([
            'conversation_id' => $this->conversation->id,
            'content' => $content,
        ]);
    }

    private function botReplyCount(): int
    {
        return Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'bot')
            ->count();
    }

    private function botReplyContents(): array
    {
        return Message::where('conversation_id', $this->conversation->id)
            ->where('sender', 'bot')
            ->orderBy('id')
            ->pluck('content')
            ->all();
    }

    private function aiReplyFor(string $userMessage): array
    {
        return [
            'content' => 'ตอบกลับ: '.$userMessage,
            'model' => 'test/model',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'cost' => 0.001,
            'rag_metadata' => [],
            'order_payload' => null,
        ];
    }

    /**
     * AIService double that records every userMessage it is asked to answer
     * and replies with an input-specific text (so each reply is traceable to
     * its input). While it is generating the FIRST answer (the holder is
     * mid-AI, lease held), $whileHolding runs inline — exactly "msg B
     * arrives while msg A is still being answered".
     */
    private function holdingAi(array &$aiInputs, ?callable $whileHolding): AIService&Mockery\MockInterface
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->twice()->andReturnUsing(
            function ($bot, string $userMessage) use (&$aiInputs, $whileHolding) {
                $aiInputs[] = $userMessage;

                if (count($aiInputs) === 1 && $whileHolding !== null) {
                    $whileHolding();
                }

                return $this->aiReplyFor($userMessage);
            }
        );

        return $ai;
    }

    private function uncallables(): array
    {
        // Mocks with no expectations: any call would fail the test.
        return [
            'ai' => Mockery::mock(AIService::class),
            'delivery' => Mockery::mock(AggregatedMessageDeliveryService::class),
        ];
    }

    private function runJob(string $groupId, $ai, $delivery): void
    {
        (new ProcessAggregatedMessages($this->bot, $this->conversation, $groupId, 'U_overlap'))
            ->handle(app(MessageAggregationService::class), $ai, $delivery);
    }

    private function lastPushedWaiter(): ProcessAggregatedMessages
    {
        $pushed = Queue::pushedJobs()[ProcessAggregatedMessages::class];
        $job = end($pushed)['job'];

        $this->assertInstanceOf(ProcessAggregatedMessages::class, $job);

        return $job;
    }

    private function assertNoPendingGroup(): void
    {
        $this->assertNull(
            MessageAggregationGroup::where('conversation_id', $this->conversation->id)->first(),
            'nothing may stay pending once every message was answered'
        );
    }

    /**
     * The exact QA X1 timeline: msg A is being answered (lease held, holder
     * mid-AI) when msg B arrives and joins the row as a NEW group [A,B]; B's
     * first attempt re-dispatches as a waiter; then the holder finishes.
     * After that, exactly ONE more reply must be generated, its AI input
     * must contain B and NOT A, and A must have been answered exactly once.
     */
    public function test_message_during_generation_gets_exactly_one_own_answer_after_holder_finishes(): void
    {
        Queue::fake();
        $aggregation = app(MessageAggregationService::class);

        $msgA = $this->userMessage('ข้อความเก่า A');
        $groupA = $aggregation->startOrContinueAggregation($this->conversation, $msgA, 1500);
        $this->assertNotNull($groupA, 'aggregation accepted msg A');

        $aiInputs = [];
        $msgB = null;
        $groupB = null;

        // Runs INSIDE the holder's generateResponse() — the lease is held
        // right now, which is precisely the overlap window.
        $whileHolding = function () use ($aggregation, $msgA, &$msgB, &$groupB) {
            $msgB = $this->userMessage('ข้อความใหม่ B');
            $groupB = $aggregation->startOrContinueAggregation($this->conversation, $msgB, 1500);

            $this->assertNotNull($groupB, 'B must be buffered while A is being answered');
            $this->assertSame([$msgA->id, $msgB->id], $aggregation->getMessageIds($this->conversation->id));

            // B's debounce fires while the fence is still held: B's first
            // attempt must re-dispatch itself as a waiter (never drop work).
            ['ai' => $silentAi, 'delivery' => $silentDelivery] = $this->uncallables();
            $this->runJob($groupB['group_id'], $silentAi, $silentDelivery);
        };

        $ai = $this->holdingAi($aiInputs, $whileHolding);
        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->twice();

        // The holder (msg A's answer) runs to completion.
        $this->runJob($groupA['group_id'], $ai, $delivery);

        // The waiter that re-dispatched while the fence was held is next.
        $waiter = $this->lastPushedWaiter();
        $this->assertSame(1, $waiter->leaseWaitAttempts, 'B re-dispatched itself while the fence was held');

        $waiter->handle(app(MessageAggregationService::class), $ai, $delivery);

        // (a) exactly one more reply, generated for B and NOT A:
        $this->assertCount(2, $aiInputs, 'exactly one more reply must be generated after the holder finishes');
        $this->assertSame('ข้อความใหม่ B', $aiInputs[1], 'the follow-up answer must cover only the new message');

        // (b) A answered exactly once in total — one reply per message:
        $this->assertSame(2, $this->botReplyCount());
        $this->assertSame(
            ['ตอบกลับ: ข้อความเก่า A', 'ตอบกลับ: ข้อความใหม่ B'],
            $this->botReplyContents(),
        );

        $this->assertNoPendingGroup();
    }

    /**
     * Same overlap with the cache throwing on every operation during the
     * whole sequence (Redis down): the DB-authoritative row alone must carry
     * B to its answer.
     */
    public function test_overlap_with_cache_down_still_answers_both_messages(): void
    {
        Queue::fake();

        Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
        Cache::shouldReceive('put')->andThrow(new RuntimeException('redis down'));
        Cache::shouldReceive('forget')->andThrow(new RuntimeException('redis down'));
        Cache::shouldReceive('add')->andThrow(new RuntimeException('redis down'));

        $aggregation = app(MessageAggregationService::class);

        $msgA = $this->userMessage('ข้อความเก่า A');
        $groupA = $aggregation->startOrContinueAggregation($this->conversation, $msgA, 1500);
        $this->assertNotNull($groupA, 'aggregation accepted msg A without cache');

        $aiInputs = [];
        $msgB = null;
        $groupB = null;

        $whileHolding = function () use ($aggregation, $msgA, &$msgB, &$groupB) {
            $msgB = $this->userMessage('ข้อความใหม่ B');
            $groupB = $aggregation->startOrContinueAggregation($this->conversation, $msgB, 1500);

            $this->assertNotNull($groupB, 'B must be buffered while A is being answered, cache or not');
            $this->assertSame([$msgA->id, $msgB->id], $aggregation->getMessageIds($this->conversation->id));

            ['ai' => $silentAi, 'delivery' => $silentDelivery] = $this->uncallables();
            $this->runJob($groupB['group_id'], $silentAi, $silentDelivery);
        };

        $ai = $this->holdingAi($aiInputs, $whileHolding);
        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->twice();

        $this->runJob($groupA['group_id'], $ai, $delivery);

        $waiter = $this->lastPushedWaiter();
        $this->assertSame(1, $waiter->leaseWaitAttempts);

        $waiter->handle(app(MessageAggregationService::class), $ai, $delivery);

        $this->assertCount(2, $aiInputs, 'exactly one more reply after the holder finishes, cache down');
        $this->assertSame('ข้อความใหม่ B', $aiInputs[1]);
        $this->assertSame(2, $this->botReplyCount());
        $this->assertSame(
            ['ตอบกลับ: ข้อความเก่า A', 'ตอบกลับ: ข้อความใหม่ B'],
            $this->botReplyContents(),
        );
        $this->assertNoPendingGroup();
    }

    // ------------------------------------------------------------------
    // Bot 26's prod path (exact prod flags)
    // ------------------------------------------------------------------

    private function prodBot(): Bot
    {
        $bot = Bot::factory()->active()->line()->create([
            'id' => 26,
            'user_id' => $this->user->id,
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_secret',
            'primary_chat_model' => 'test/primary-model',
        ]);

        BotSetting::create([
            'bot_id' => 26,
            'wait_multiple_bubbles_enabled' => true,
            'wait_multiple_bubbles_ms' => 1500,
        ]);

        return $bot;
    }

    private function lineEvent(string $text): array
    {
        $key = md5($text);

        return [
            'type' => 'message',
            'replyToken' => 'reply_token_'.$key,
            'source' => ['type' => 'user', 'userId' => 'U_prod_26'],
            'message' => [
                'id' => 'msg_'.$key,
                'type' => 'text',
                'text' => $text,
            ],
            'webhookEventId' => 'webhook_'.$key,
            'deliveryContext' => ['isRedelivery' => false],
            'timestamp' => time() * 1000,
        ];
    }

    private function runProdLineJob(Bot $bot, array $event): void
    {
        $job = new ProcessLINEWebhook($bot, $event);

        app()->call([$job, 'handle']);
    }

    /**
     * The whole overlap through bot 26's real prod entry point
     * (ProcessLINEWebhook → v2 pipeline) with the exact prod flags.
     */
    public function test_overlap_through_bot_26_prod_pipeline_answers_both_messages(): void
    {
        Queue::fake();

        // Exact prod flags (Release card, 2026-10-25):
        config([
            'webhook_pipeline_v2.enabled' => true,
            'webhook_pipeline_v2.bot_ids' => ['26'],
            'line_webhook.pipeline_enabled' => true,
            'line_webhook.pipeline_bot_ids' => ['26'],
        ]);

        $bot = $this->prodBot();

        Http::fake([
            'openrouter.ai/*' => fn () => Http::response(['data' => []], 200),
            'api.line.me/*' => fn () => Http::response(['ok' => true], 200),
        ]);

        // Intake of msg A through the real pipeline (aggregation enabled →
        // buffered, ProcessAggregatedMessages dispatched).
        $this->runProdLineJob($bot, $this->lineEvent('ข้อความเก่า A'));

        Queue::assertPushed(ProcessAggregatedMessages::class, 1);
        $holder = $this->lastPushedWaiter();
        $aggregation = app(MessageAggregationService::class);
        $conversation = Conversation::where('bot_id', 26)->first();
        $msgA = $conversation->messages()->where('content', 'ข้อความเก่า A')->first();

        $aiInputs = [];

        $whileHolding = function () use ($bot, $conversation, $msgA, $aggregation, &$aiInputs) {
            // Msg B arrives through the real pipeline while the holder is
            // mid-AI (lease held): it must join the pending row.
            $this->runProdLineJob($bot, $this->lineEvent('ข้อความใหม่ B'));

            $msgB = $conversation->messages()->where('content', 'ข้อความใหม่ B')->first();
            $this->assertNotNull($msgB);
            $this->assertSame(
                [$msgA->id, $msgB->id],
                $aggregation->getMessageIds($conversation->id),
                'B joins the pending group while A is being answered'
            );

            // B's debounce attempt while the fence is still held.
            ['ai' => $silentAi, 'delivery' => $silentDelivery] = $this->uncallables();
            $bAttempt = $this->lastPushedWaiter();
            $bAttempt->handle(app(MessageAggregationService::class), $silentAi, $silentDelivery);
        };

        $ai = $this->holdingAi($aiInputs, $whileHolding);
        $this->app->instance(AIService::class, $ai);
        $delivery = app(AggregatedMessageDeliveryService::class);

        // The holder runs to completion (A's answer goes out over "LINE").
        $holder->handle(app(MessageAggregationService::class), $ai, $delivery);

        // B's waiter (re-dispatched while the fence was held).
        $waiter = $this->lastPushedWaiter();
        $this->assertSame(1, $waiter->leaseWaitAttempts);

        $waiter->handle(app(MessageAggregationService::class), $ai, $delivery);

        $this->assertCount(2, $aiInputs, 'exactly one more reply through the prod path');
        $this->assertSame('ข้อความใหม่ B', $aiInputs[1]);
        $this->assertSame(
            ['ตอบกลับ: ข้อความเก่า A', 'ตอบกลับ: ข้อความใหม่ B'],
            $conversation->messages()->where('sender', 'bot')->orderBy('id')->pluck('content')->all(),
        );
        $this->assertNull(
            MessageAggregationGroup::where('conversation_id', $conversation->id)->first(),
        );
    }

    // ------------------------------------------------------------------
    // Throw AFTER the holder's reply row is persisted (review round 1,
    // PR #309): the handover must not depend on the steps that follow the
    // persisted reply (delivery, timing, snapshot, stats). Clock is frozen
    // so the holder's reply and B's arrival share one second — the exact
    // same-second overlap that once made the safety net suppress B and
    // would now make the waiter re-answer A.
    // ------------------------------------------------------------------

    private function frozenOverlap(array &$aiInputs): array
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        Queue::fake();

        $aggregation = app(MessageAggregationService::class);
        $msgA = $this->userMessage('ข้อความเก่า A');
        $groupA = $aggregation->startOrContinueAggregation($this->conversation, $msgA, 1500);
        $this->assertNotNull($groupA, 'aggregation accepted msg A');

        // Runs INSIDE the holder's generateResponse(): B arrives as a NEW
        // group [A,B] while the holder holds the lease.
        $whileHolding = function () use ($aggregation, $msgA, &$aiInputs) {
            $msgB = $this->userMessage('ข้อความใหม่ B');
            $groupB = $aggregation->startOrContinueAggregation($this->conversation, $msgB, 1500);
            $this->assertSame([$msgA->id, $msgB->id], $aggregation->getMessageIds($this->conversation->id));

            // B's debounce attempt while the fence is still held.
            ['ai' => $silentAi, 'delivery' => $silentDelivery] = $this->uncallables();
            $this->runJob($groupB['group_id'], $silentAi, $silentDelivery);
        };

        $ai = $this->holdingAi($aiInputs, $whileHolding);

        return [$aggregation, $ai, $groupA['group_id']];
    }

    /**
     * updateStats throws AFTER the holder's reply row exists (stats write
     * failure). The waiter must still answer exactly "B" — never "A\nB".
     */
    public function test_holder_stats_step_throwing_after_reply_persists_still_answers_b_once(): void
    {
        $aiInputs = [];
        [$aggregation, $ai, $holderGroupId] = $this->frozenOverlap($aiInputs);

        $delivered = [];
        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->twice()->andReturnUsing(
            function ($bot, $conversation, $message) use (&$delivered): void {
                $delivered[] = $message->content;
            }
        );

        $holder = new class($this->bot, $this->conversation, $holderGroupId, 'U_overlap') extends ProcessAggregatedMessages
        {
            protected function updateStats(int $aggregatedMessageCount, int $lastMessageId): void
            {
                throw new LogicException('stats write failed');
            }
        };

        try {
            $holder->handle($aggregation, $ai, $delivery);
            $this->fail('the stats step was expected to throw after the reply was persisted');
        } catch (LogicException $e) {
            $this->assertSame('stats write failed', $e->getMessage());
        }

        // The waiter that re-dispatched while the fence was held.
        $waiter = $this->lastPushedWaiter();
        $waiter->handle($aggregation, $ai, $delivery);

        $this->assertSame(
            ['ข้อความเก่า A', 'ข้อความใหม่ B'],
            $aiInputs,
            'the waiter must answer exactly B — never re-answer A ("A\nB")'
        );
        $this->assertSame(
            ['ตอบกลับ: ข้อความเก่า A', 'ตอบกลับ: ข้อความใหม่ B'],
            $this->botReplyContents(),
            'each message answered exactly once'
        );
        $this->assertSame(
            ['ตอบกลับ: ข้อความเก่า A', 'ตอบกลับ: ข้อความใหม่ B'],
            $delivered,
        );
        $this->assertNoPendingGroup();
    }

    /**
     * deliver throws AFTER the holder's reply row exists (LINE push error).
     * The waiter must answer exactly "B"; A's persisted reply must never be
     * re-answered. (Re-delivering the failed push is out of scope — the
     * assertion here is that nothing is answered TWICE.)
     */
    public function test_holder_delivery_throwing_after_reply_persists_still_answers_b_without_re_answering_a(): void
    {
        $aiInputs = [];
        [$aggregation, $ai, $holderGroupId] = $this->frozenOverlap($aiInputs);

        $delivered = [];
        $deliverCalls = 0;
        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->twice()->andReturnUsing(
            function ($bot, $conversation, $message) use (&$delivered, &$deliverCalls): void {
                if (++$deliverCalls === 1) {
                    throw new RuntimeException('LINE push failed (holder)');
                }

                $delivered[] = $message->content;
            }
        );

        try {
            $this->runJob($holderGroupId, $ai, $delivery);
            $this->fail('the delivery step was expected to throw after the reply was persisted');
        } catch (RuntimeException $e) {
            $this->assertSame('LINE push failed (holder)', $e->getMessage());
        }

        $waiter = $this->lastPushedWaiter();
        $waiter->handle($aggregation, $ai, $delivery);

        $this->assertSame(
            ['ข้อความเก่า A', 'ข้อความใหม่ B'],
            $aiInputs,
            'the waiter must answer exactly B — never re-answer A ("A\nB")'
        );
        $this->assertSame(
            ['ตอบกลับ: ข้อความเก่า A', 'ตอบกลับ: ข้อความใหม่ B'],
            $this->botReplyContents(),
            'A was answered exactly once (its reply row persisted even though the push failed)'
        );
        $this->assertSame(
            ['ตอบกลับ: ข้อความใหม่ B'],
            $delivered,
            'the holder push failed, the waiter push succeeded'
        );
        $this->assertNoPendingGroup();
    }

    // ------------------------------------------------------------------
    // hasAlreadyResponded() safety net (review round 1, PR #309): a real
    // covering reply written in a LATER second than the row's last
    // mutation must make the job skip without calling the AI. This is the
    // duplicate guard itself — it must fail if the check is disabled or
    // its boundary shifts (verified by mutation below the suite).
    // ------------------------------------------------------------------

    public function test_covering_reply_in_a_later_second_skips_the_job_without_calling_ai(): void
    {
        Queue::fake();
        $aggregation = app(MessageAggregationService::class);

        Carbon::setTestNow('2026-10-25 12:00:00');
        $msgA = $this->userMessage('ข้อความเก่า A');
        $groupA = $aggregation->startOrContinueAggregation($this->conversation, $msgA, 1500);
        $this->assertNotNull($groupA, 'aggregation accepted msg A');

        // A bot reply lands in a LATER second and covers the pending group
        // (e.g. an admin replied, or a previous turn's reply raced ahead of
        // the row mutation). hasAlreadyResponded() must stop the job.
        Carbon::setTestNow('2026-10-25 12:01:00');
        Message::factory()->fromBot()->create([
            'conversation_id' => $this->conversation->id,
            'content' => 'ตอบกลับ: ข้อความเก่า A',
        ]);

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->never();
        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->never();

        $this->runJob($groupA['group_id'], $ai, $delivery);

        $this->assertNoPendingGroup('the skipped job must still release its group');
    }

    // ------------------------------------------------------------------
    // handOverReplyAndHandOverGroup() atomicity guard (X1 follow-up to
    // PR #309): the holder's reply row and the group handover commit as
    // ONE database transaction. Reviewer mutation on #309 head 10149e81 —
    // DB::transaction( replaced by a plain closure call — kept the whole
    // suite green, so nothing failed when a throwing handover left the
    // reply row committed while its message ids stayed pending (the
    // duplicate window the atomic commit exists to close).
    // ------------------------------------------------------------------

    /**
     * handOverAnsweredIds() throws while the holder's reply is being
     * persisted: the whole unit must roll back — no reply row, delivery
     * never reached, and the job fails loudly (tries=1: the customer's
     * next message re-drives the conversation).
     */
    public function test_holder_handover_throwing_rolls_back_the_reply_and_never_delivers(): void
    {
        Queue::fake();

        // A MessageAggregationService whose handOverAnsweredIds() throws;
        // every other method behaves exactly like the real one.
        $aggregation = new class extends MessageAggregationService
        {
            public function handOverAnsweredIds(int $conversationId, string $groupId, array $answeredMessageIds): void
            {
                throw new RuntimeException('handover failed');
            }
        };
        $this->app->instance(MessageAggregationService::class, $aggregation);

        $msgA = $this->userMessage('ข้อความเก่า A');
        $groupA = $aggregation->startOrContinueAggregation($this->conversation, $msgA, 1500);
        $this->assertNotNull($groupA, 'aggregation accepted msg A');

        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andReturnUsing(
            fn ($bot, string $userMessage): array => $this->aiReplyFor($userMessage)
        );

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->never();

        try {
            $this->runJob($groupA['group_id'], $ai, $delivery);
            $this->fail('the handover failure must abort the holder job');
        } catch (RuntimeException $e) {
            $this->assertSame('handover failed', $e->getMessage());
        }

        $this->assertSame(
            0,
            $this->botReplyCount(),
            'the reply row must roll back together with the throwing handover'
        );
    }
}
