<?php

namespace Tests\Feature\Metrics;

use App\Jobs\ProcessAggregatedMessages;
use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\ConversationResponseLease;
use App\Services\MessageAggregationService;
use App\Services\RAGService;
use App\Services\ReplyPhaseTimingCollector;
use App\Services\StockGuardService;
use App\Services\VipPriceGuardService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 2 · plan #6 timing half: one row per bot reply attempt in
 * reply_phase_timings — where does the customer's wait go?
 *
 * Fake clock (Carbon::setTestNow) + faked HTTP (OpenRouter slow / timeout /
 * 429, LINE API) make every phase duration deterministic in milliseconds.
 */
class ReplyPhaseTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouter.api_key' => 'test-key',
            'circuit-breaker.enabled' => false,
            'delivery.order_payload_enabled' => false,
            'line_webhook.pipeline_enabled' => false,
            'webhook_pipeline_v2.enabled' => false,
        ]);

        Carbon::setTestNow($this->t0());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function t0(): Carbon
    {
        return Carbon::parse('2026-10-25 10:00:00');
    }

    private function advanceMs(int $ms): void
    {
        Carbon::setTestNow(Carbon::now()->addMilliseconds($ms));
    }

    private function makeBot(int $id, array $attrs = []): Bot
    {
        return Bot::factory()->active()->line()->create([
            'id' => $id,
            'user_id' => User::factory()->create()->id,
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_secret',
            'primary_chat_model' => 'test/primary-model',
            'fallback_chat_model' => 'test/fallback-model',
            'context_window' => 10,
            ...$attrs,
        ]);
    }

    private function lineEvent(string $text, string $userId = 'U_user_28'): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'reply_token_'.md5($text.$userId),
            'source' => ['type' => 'user', 'userId' => $userId],
            'message' => [
                'id' => 'msg_'.md5($text.$userId.uniqid('', true)),
                'type' => 'text',
                'text' => $text,
            ],
            'webhookEventId' => 'webhook_'.md5($text.$userId),
            'deliveryContext' => ['isRedelivery' => false],
            'timestamp' => 1761360000000,
        ];
    }

    private function chatResponse(string $model, string $content = 'คำตอบจากบอท QQreplyZZ')
    {
        return Http::response([
            'id' => 'gen-1',
            'model' => $model,
            'choices' => [
                ['message' => ['content' => $content], 'finish_reason' => 'stop'],
            ],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 8, 'total_tokens' => 20],
        ], 200);
    }

    /**
     * Fake OpenRouter + LINE HTTP. $onOpenRouter receives the 1-based call
     * count and must return a Response (default: advance $genMs + succeed).
     */
    private function fakeHttp(
        int $genMs = 3000,
        string $model = 'test/primary-model',
        int $lineMs = 200,
        ?callable $onOpenRouter = null,
    ): void {
        Http::fake([
            // Chat completions only — the capability service also GETs the models
            // list on openrouter.ai, which must not consume chat time or payloads.
            'openrouter.ai/api/v1/chat/completions' => function () use ($genMs, $model, $onOpenRouter) {
                if ($onOpenRouter !== null) {
                    return $onOpenRouter();
                }

                $this->advanceMs($genMs);

                return $this->chatResponse($model);
            },
            'openrouter.ai/*' => fn () => Http::response(['data' => []], 200),
            'api.line.me/*' => function () use ($lineMs) {
                $this->advanceMs($lineMs);

                return Http::response(['ok' => true], 200);
            },
        ]);
    }

    private function runLineJob(Bot $bot, array $event): void
    {
        Carbon::setTestNow(Carbon::now()); // pin construction time
        $job = new ProcessLINEWebhook($bot, $event);
        $this->advanceMs(250); // webhook controller → queue → worker pickup

        app()->call([$job, 'handle']);
    }

    private function timingRowFor(int $botId): ?object
    {
        return DB::table('reply_phase_timings')->where('bot_id', $botId)->first();
    }

    // ------------------------------------------------------------------
    // LINE legacy path (prod bot 28)
    // ------------------------------------------------------------------

    public function test_legacy_line_path_records_one_phase_timing_row(): void
    {
        $bot = $this->makeBot(28);
        $this->fakeHttp(genMs: 3000, model: 'test/primary-model', lineMs: 200);

        // 'สวัสดี' matches RAGIntentDetector's SIMPLE_MESSAGE_PATTERN → intent
        // decision call is skipped, so the only OpenRouter call is the primary.
        $this->runLineJob($bot, $this->lineEvent('สวัสดี'));

        $row = $this->timingRowFor(28);
        $this->assertNotNull($row, 'Legacy LINE path must write one reply_phase_timings row');

        $conversation = Conversation::where('bot_id', 28)->first();
        $this->assertSame($conversation->id, $row->conversation_id);
        $this->assertSame('success', $row->outcome);
        $this->assertSame('test/primary-model', $row->model);

        // Fake clock deltas: 250ms queue wait, 3000ms primary, 200ms send.
        $this->assertSame(250, $row->queue_wait_ms);
        $this->assertNull($row->aggregation_wait_ms, 'Direct replies have no debounce');
        $this->assertSame(3000, $row->primary_ms);
        $this->assertNull($row->fallback_ms);
        $this->assertNull($row->vip_retry_ms);
        $this->assertNull($row->intent_ms, 'Simple greeting skips the intent decision call');
        $this->assertNull($row->retrieval_ms, 'No knowledge base configured');
        $this->assertSame(200, $row->send_ms);
        $this->assertNotNull($row->plugin_ms);
        $this->assertGreaterThanOrEqual(3200, $row->total_ms);
        // 3200 fake ms + real in-process work (plugins, stats, DB, broadcasts)
        $this->assertLessThan(4500, $row->total_ms);
        // Router disabled → the decide() short-circuit is sub-100ms.
        $this->assertNotNull($row->router_ms);
        $this->assertLessThan(100, $row->router_ms);
        $this->assertNotNull($row->created_at);
    }

    /**
     * Prod bot 26 path: WebhookPipelineV2Flag is checked FIRST in handle(),
     * so prod runs the shared v2 pipeline (LineEventGateStep → LinePipelineStep),
     * not the runPipeline() path — the row must be written here too.
     */
    public function test_line_v2_pipeline_records_one_phase_timing_row(): void
    {
        config([
            'webhook_pipeline_v2.enabled' => true,
            'webhook_pipeline_v2.bot_ids' => ['26'],
        ]);

        $bot = $this->makeBot(26, ['primary_chat_model' => 'test/primary-model']);
        $this->fakeHttp(genMs: 2500, model: 'test/primary-model', lineMs: 300);

        $this->runLineJob($bot, $this->lineEvent('สวัสดีครับ pipeline', 'U_user_26'));

        $row = $this->timingRowFor(26);
        $this->assertNotNull($row, 'LINE v2 shared pipeline (prod bot 26) must write one reply_phase_timings row');
        $conversation = Conversation::where('bot_id', 26)->first();
        $this->assertSame($conversation->id, $row->conversation_id);
        $this->assertSame('success', $row->outcome);
        $this->assertSame('test/primary-model', $row->model);
        $this->assertSame(250, $row->queue_wait_ms);
        $this->assertSame(2500, $row->primary_ms);
        $this->assertNull($row->fallback_ms);
        $this->assertSame(300, $row->send_ms);
        $this->assertGreaterThanOrEqual(2750, $row->total_ms);
    }

    /**
     * The runPipeline() path (line_webhook.pipeline_* without v2) keeps its row.
     */
    public function test_line_pipeline_path_records_one_phase_timing_row(): void
    {
        config([
            'line_webhook.pipeline_enabled' => true,
            'line_webhook.pipeline_bot_ids' => ['26'],
        ]);

        $bot = $this->makeBot(26, ['primary_chat_model' => 'test/primary-model']);
        $this->fakeHttp(genMs: 2500, model: 'test/primary-model', lineMs: 300);

        $this->runLineJob($bot, $this->lineEvent('สวัสดีครับ pipeline', 'U_user_26'));

        $row = $this->timingRowFor(26);
        $this->assertNotNull($row, 'runPipeline path must still write one reply_phase_timings row');
        $this->assertSame('success', $row->outcome);
        $this->assertSame(2500, $row->primary_ms);
        $this->assertSame(300, $row->send_ms);
    }

    /**
     * A non-OpenRouter exception escaping the v2 pipeline must still leave a
     * failed row WITH the conversation id (markAttempted happens before the
     * AI call, the row is written in the catch around WebhookPipeline::run()).
     */
    public function test_line_v2_pipeline_failure_still_writes_failed_row(): void
    {
        config([
            'webhook_pipeline_v2.enabled' => true,
            'webhook_pipeline_v2.bot_ids' => ['26'],
        ]);

        $bot = $this->makeBot(26);
        $this->mock(AIService::class, function ($mock) {
            $mock->shouldReceive('generateAndSaveResponse')->once()->andThrow(new \RuntimeException('boom'));
        });

        try {
            $this->runLineJob($bot, $this->lineEvent('สวัสดีครับ', 'U_user_26'));
            $this->fail('The non-OpenRouter exception must still propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $row = $this->timingRowFor(26);
        $this->assertNotNull($row, 'Failed v2 pipeline attempt must still write its row');
        $conversation = Conversation::where('bot_id', 26)->first();
        $this->assertSame($conversation->id, $row->conversation_id);
        $this->assertSame('failed', $row->outcome);
    }

    /**
     * Lock held in the v2 pipeline: the aggregation fallback dispatch must
     * carry the 15000ms debounce, or that wait lands in queue_wait_ms.
     */
    public function test_line_v2_pipeline_lock_fallback_dispatches_with_debounce(): void
    {
        Queue::fake();
        config([
            'webhook_pipeline_v2.enabled' => true,
            'webhook_pipeline_v2.bot_ids' => ['26'],
        ]);

        $bot = $this->makeBot(26);
        $conversation = Conversation::factory()->line()->create([
            'bot_id' => 26,
            'external_customer_id' => 'U_user_26',
        ]);
        app(ConversationResponseLease::class)->acquire($conversation->id);
        $this->fakeHttp();

        $this->runLineJob($bot, $this->lineEvent('สวัสดีครับ', 'U_user_26'));

        Queue::assertPushed(ProcessAggregatedMessages::class, function ($job) {
            return $job->intendedDebounceMs === 15000;
        });
    }

    // ------------------------------------------------------------------
    // Aggregated replies: debounce reported separately from processing
    // ------------------------------------------------------------------

    public function test_aggregation_dispatch_carries_intended_debounce(): void
    {
        Queue::fake();

        $bot = $this->makeBot(28);
        BotSetting::create([
            'bot_id' => 28,
            'wait_multiple_bubbles_enabled' => true,
            'wait_multiple_bubbles_ms' => 5000,
        ]);
        $this->fakeHttp();

        $this->runLineJob($bot, $this->lineEvent('ข้อความแรกครับ'));

        Queue::assertPushed(ProcessAggregatedMessages::class, function ($job) {
            return $job->intendedDebounceMs === 5000;
        });
    }

    public function test_aggregated_job_reports_debounce_separate_from_processing(): void
    {
        $bot = $this->makeBot(28);
        $conversation = Conversation::factory()->line()->create([
            'bot_id' => 28,
            'external_customer_id' => 'U_user_28',
            'message_count' => 1,
            'unread_count' => 0,
            'is_handover' => false,
        ]);
        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'ข้อความแรกครับ',
        ]);

        $aggregation = app(MessageAggregationService::class);
        $group = $aggregation->startOrContinueAggregation($conversation, $userMessage, 0);
        $this->assertNotNull($group);

        $this->fakeHttp(genMs: 3000, model: 'test/primary-model', lineMs: 200);

        Carbon::setTestNow($this->t0());
        $job = new ProcessAggregatedMessages($bot, $conversation, $group['group_id'], 'U_user_28', 5000);
        // Queue pickup latency: 150ms ON TOP of the intended 5000ms debounce.
        $this->advanceMs(5150);

        app()->call([$job, 'handle']);

        $row = $this->timingRowFor(28);
        $this->assertNotNull($row, 'Aggregated reply attempt must write one row');
        $this->assertSame(5000, $row->aggregation_wait_ms, 'Intended debounce reported verbatim');
        $this->assertSame(150, $row->queue_wait_ms, 'Queue wait is the extra wait beyond the debounce');
        $this->assertSame(3000, $row->primary_ms);
        $this->assertSame(200, $row->send_ms);
        $this->assertNull($row->plugin_ms, 'Aggregated plugins are dispatched async, not inline');
        $this->assertSame($conversation->id, $row->conversation_id);
        $this->assertSame($group['group_id'], $row->aggregation_group_id);
        $this->assertSame('success', $row->outcome);
    }

    // ------------------------------------------------------------------
    // Slow primary → client-side fallback
    // ------------------------------------------------------------------

    public function test_slow_primary_falls_back_and_records_both_phases(): void
    {
        $bot = $this->makeBot(28);
        $calls = 0;
        $this->fakeHttp(
            lineMs: 200,
            onOpenRouter: function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    $this->advanceMs(90000); // primary read-timeout window

                    throw new ConnectionException('cURL error 28: timeout');
                }

                $this->advanceMs(45000); // fallback model generation

                return $this->chatResponse('test/fallback-model');
            },
        );

        $this->runLineJob($bot, $this->lineEvent('hello'));

        $row = $this->timingRowFor(28);
        $this->assertNotNull($row);
        $this->assertSame(90000, $row->primary_ms, 'Failed primary attempt still counts its time');
        $this->assertSame(45000, $row->fallback_ms);
        $this->assertSame('test/fallback-model', $row->model);
        $this->assertSame('success', $row->outcome);
    }

    // ------------------------------------------------------------------
    // Fallback ALSO fails → its time must still be recorded
    // ------------------------------------------------------------------

    public function test_failed_fallback_still_records_its_time(): void
    {
        $bot = $this->makeBot(28);
        $calls = 0;
        $this->fakeHttp(
            lineMs: 200,
            onOpenRouter: function () use (&$calls) {
                $calls++;
                if ($calls === 1) {
                    $this->advanceMs(90000); // primary read-timeout window

                    throw new ConnectionException('cURL error 28: timeout');
                }

                $this->advanceMs(45000); // fallback model also times out

                throw new ConnectionException('fallback model timed out');
            },
        );

        $this->runLineJob($bot, $this->lineEvent('hello'));

        $row = $this->timingRowFor(28);
        $this->assertNotNull($row);
        $this->assertSame(90000, $row->primary_ms);
        $this->assertSame(45000, $row->fallback_ms, 'A failed fallback must still record its time');
        $this->assertSame('ai_failed', $row->outcome);
        $this->assertNull($row->model, 'No model answered');
    }

    // ------------------------------------------------------------------
    // 429 rate limit → friendly error reply, outcome ai_failed
    // ------------------------------------------------------------------

    public function test_rate_limited_reply_records_failed_outcome(): void
    {
        $bot = $this->makeBot(28);
        $this->fakeHttp(
            lineMs: 200,
            onOpenRouter: function () {
                $this->advanceMs(2000);

                return Http::response(['error' => ['message' => 'Rate limit exceeded']], 429);
            },
        );

        $this->runLineJob($bot, $this->lineEvent('สวัสดีครับ'));

        $row = $this->timingRowFor(28);
        $this->assertNotNull($row);
        // 6000 = OpenRouterService's own 429-retry loop: 3 HTTP attempts × 2000ms,
        // all inside the primary phase (retry policy untouched — measurement only).
        $this->assertSame(6000, $row->primary_ms);
        $this->assertNull($row->fallback_ms);
        $this->assertSame('ai_failed', $row->outcome);
        $this->assertNull($row->model, 'No model answered — friendly error was sent instead');
        // The reply WAS still sent (friendly error), so send phase is recorded.
        $this->assertSame(200, $row->send_ms);
    }

    // ------------------------------------------------------------------
    // VIP price correction retry
    // ------------------------------------------------------------------

    public function test_vip_price_retry_records_vip_retry_phase(): void
    {
        $bot = Bot::factory()->create(['context_window' => 10]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        $this->mock(RAGService::class, function ($mock) {
            $mock->shouldReceive('generateResponse')->twice()->andReturnUsing(function () {
                $this->advanceMs(1000);

                return [
                    'content' => 'ราคา 1,100 บาทครับ',
                    'model' => 'test/primary-model',
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                    'cost' => 0.0,
                ];
            });
        });
        $this->mock(StockGuardService::class, function ($mock) {
            $mock->shouldReceive('validate')->twice()->andReturn(['blocked' => false]);
        });
        $this->mock(VipPriceGuardService::class, function ($mock) {
            $mock->shouldReceive('enforce')->twice()->andReturn(
                [
                    'content' => 'ราคา 1,100 บาทครับ',
                    'order_payload' => null,
                    'corrected' => true,
                    'reason' => [
                        'type' => 'informational_price',
                        'line' => 'ราคา 1,100 บาท',
                        'amounts' => [1100.0],
                        'expected' => 1000.0,
                    ],
                ],
                [
                    'content' => 'ราคา 1,000 บาทครับ',
                    'order_payload' => null,
                    'corrected' => false,
                    'reason' => null,
                ],
            );
        });

        $timing = ReplyPhaseTimingCollector::start();
        $result = app(AIService::class)->generateResponse($bot, 'ราคาเท่าไหร่ครับ', $conversation, [], $timing);

        $row = $timing->toRow();
        $this->assertSame('ราคา 1,000 บาทครับ', $result['content']);
        $this->assertGreaterThanOrEqual(1000, $row['vip_retry_ms'], 'Second guard pass wall time');
        $this->assertLessThan(1200, $row['vip_retry_ms']);
        $this->assertNull($row['primary_ms'], 'RAG is mocked here; primary timing belongs to OpenRouterService tests');
    }

    // ------------------------------------------------------------------
    // Best-effort: a DB failure writing the row must not change the reply
    // ------------------------------------------------------------------

    public function test_db_failure_writing_row_does_not_change_reply(): void
    {
        $bot = $this->makeBot(28);
        Schema::drop('reply_phase_timings');
        $this->fakeHttp(genMs: 1000, model: 'test/primary-model', lineMs: 100);

        $this->runLineJob($bot, $this->lineEvent('สวัสดีครับ'));

        // No exception escaped the job, and the customer still got the reply.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.line.me'));
        $conversation = Conversation::where('bot_id', 28)->first();
        $botMessage = Message::where('conversation_id', $conversation->id)->where('sender', 'bot')->first();
        $this->assertNotNull($botMessage, 'Reply must be unaffected by the timing-write failure');
    }

    // ------------------------------------------------------------------
    // Privacy: no message text in the row
    // ------------------------------------------------------------------

    public function test_row_contains_no_message_text(): void
    {
        $bot = $this->makeBot(28);
        $this->fakeHttp(genMs: 1000, model: 'test/primary-model', lineMs: 100);

        $this->runLineJob($bot, $this->lineEvent('XyZZQQ_secret_marker_ข้อความลูกค้า'));

        $row = $this->timingRowFor(28);
        $this->assertNotNull($row);
        $serialized = json_encode($row);
        $this->assertStringNotContainsString('XyZZQQ_secret_marker', $serialized);
        $this->assertStringNotContainsString('QQreplyZZ', $serialized, 'Bot reply text must not leak into the row either');
    }

    // ------------------------------------------------------------------
    // The row write happens after the reply was already sent
    // ------------------------------------------------------------------

    public function test_row_written_after_reply_send(): void
    {
        $bot = $this->makeBot(28);
        $this->fakeHttp(genMs: 1000, model: 'test/primary-model', lineMs: 100);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->runLineJob($bot, $this->lineEvent('สวัสดีครับ'));

        $statsPos = null;
        foreach ($queries as $i => $sql) {
            if (str_starts_with($sql, 'update "conversations"')) {
                $statsPos = $i; // last stats update happens after LINE send + plugins
            }
        }
        $rowPos = null;
        foreach ($queries as $i => $sql) {
            if (str_starts_with($sql, 'insert into "reply_phase_timings"')) {
                $rowPos = $i;
            }
        }

        $this->assertNotNull($statsPos, 'Stats update must run (reply was sent)');
        $this->assertNotNull($rowPos, 'Timing row must be written');
        $this->assertGreaterThan($statsPos, $rowPos, 'Row write must come after the send/stats work, never before the reply');
    }

    // ------------------------------------------------------------------
    // Added latency budget: measurement overhead per reply < 5 ms
    // ------------------------------------------------------------------

    public function test_collector_overhead_below_5ms_per_reply(): void
    {
        Carbon::setTestNow(); // real clock for a honest benchmark
        $phases = ['router_ms', 'intent_ms', 'retrieval_ms', 'primary_ms', 'fallback_ms', 'vip_retry_ms', 'send_ms', 'plugin_ms'];

        $start = hrtime(true);
        $replies = 500;
        for ($i = 0; $i < $replies; $i++) {
            $collector = ReplyPhaseTimingCollector::start();
            foreach ($phases as $phase) {
                $collector->measureFn(fn () => null, $phase);
            }
            $collector->toRow();
        }
        $perReplyMs = (hrtime(true) - $start) / 1e6 / $replies;

        $this->assertLessThan(5.0, $perReplyMs, "Collector overhead per reply was {$perReplyMs}ms");
    }

    // ------------------------------------------------------------------
    // replies:timing-report — p50/p90/p99 per phase and per bot
    // ------------------------------------------------------------------

    public function test_timing_report_prints_percentiles_per_phase_and_bot(): void
    {
        $now = Carbon::now();
        $rows = [
            // bot 28: primary 1000/2000/3000 (nearest-rank p50=2000 p90=3000 p99=3000)
            ['bot_id' => 28, 'primary_ms' => 1000, 'send_ms' => 100, 'outcome' => 'success', 'model' => 'test/primary-model', 'created_at' => $now->copy()->subDays(1)],
            ['bot_id' => 28, 'primary_ms' => 2000, 'send_ms' => 150, 'outcome' => 'success', 'model' => 'test/primary-model', 'created_at' => $now->copy()->subDays(2)],
            ['bot_id' => 28, 'primary_ms' => 3000, 'send_ms' => 200, 'outcome' => 'success', 'model' => 'test/primary-model', 'created_at' => $now->copy()->subDays(3)],
            // bot 26: single row
            ['bot_id' => 26, 'primary_ms' => 5000, 'send_ms' => 300, 'outcome' => 'success', 'model' => 'test/primary-model', 'created_at' => $now->copy()->subDays(1)],
            // outside the 7-day window → excluded
            ['bot_id' => 28, 'primary_ms' => 99000, 'send_ms' => 100, 'outcome' => 'success', 'model' => 'test/primary-model', 'created_at' => $now->copy()->subDays(10)],
        ];
        foreach ($rows as $row) {
            DB::table('reply_phase_timings')->insert($row);
        }

        $kernel = $this->app->make(Kernel::class);
        $exit = $kernel->call('replies:timing-report', ['--days' => '7']);
        $output = $kernel->output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('4 rows', $output, 'Only in-window rows counted');
        $this->assertStringContainsString('primary_ms', $output);
        $this->assertStringContainsString('send_ms', $output);
        $this->assertStringContainsString('p50', $output);
        $this->assertStringContainsString('p90', $output);
        $this->assertStringContainsString('p99', $output);
        $this->assertStringContainsString('2000', $output, 'bot 28 primary p50');
        $this->assertStringContainsString('5000', $output, 'bot 26 section');
        $this->assertStringContainsString('bot 28', $output);
        $this->assertStringContainsString('bot 26', $output);
        $this->assertStringNotContainsString('99000', $output, 'Out-of-window row excluded');
    }

    // ------------------------------------------------------------------
    // Prune: rows older than 30 days are deleted
    // ------------------------------------------------------------------

    public function test_prune_command_deletes_rows_older_than_30_days(): void
    {
        $now = Carbon::now();
        DB::table('reply_phase_timings')->insert([
            ['bot_id' => 28, 'primary_ms' => 1000, 'outcome' => 'success', 'model' => 'test/primary-model', 'created_at' => $now->copy()->subDays(31)],
            ['bot_id' => 28, 'primary_ms' => 2000, 'outcome' => 'success', 'model' => 'test/primary-model', 'created_at' => $now->copy()->subDays(5)],
        ]);

        $kernel = $this->app->make(Kernel::class);
        $exit = $kernel->call('replies:prune-timings');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Pruned 1', $kernel->output());
        $this->assertSame(1, DB::table('reply_phase_timings')->count());
        $this->assertSame(2000, DB::table('reply_phase_timings')->value('primary_ms'));
    }
}
