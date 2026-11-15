<?php

namespace Tests\Feature\FollowUp;

use App\Jobs\ProcessCustomerFollowUps;
use App\Jobs\ReconcileCustomerFollowUps;
use App\Jobs\SendCustomerFollowUp;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\FollowUp\FollowUpOutcomeService;
use App\Services\FollowUp\FollowUpPipeline;
use App\Services\FollowUp\FollowUpReservationService;
use App\Services\FollowUp\FollowUpSalesProjector;
use App\Services\FollowUp\FollowUpSendService;
use App\Services\FollowUp\FollowUpTelegramService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class ReconcileJobTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-16 10:00:00';

    private const OLD = '2026-11-16 09:00:00';

    private const TEXT = "สวัสดีครับพี่ เมื่อวานที่คุยเรื่องบัญชีโฆษณาไว้\nมีจุดไหนให้ช่วยดูเพิ่มไหมครับ";

    private const CHECK = '{"scores":{"safe_to_send":0.9},"failed":[],"error":false,"version":1}';

    private const NONCE = 'abcd1234abcd1234';

    private const MARKER = 'private-exception-canary-b6f';

    private array $world;

    private array $requests = [];

    private array $refreshIds = [];

    private array $refreshResults = [];

    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeze(self::NOW);
        Queue::fake();
        Http::preventStrayRequests();
        $this->fakeHttp();
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logs[] = $event;
        });
        // Empty allowlist and an OFF bot must not gate reconciliation (D109).
        config(['customer_follow_up.bot_ids' => [], 'customer_follow_up.automatic_bot_ids' => []]);
        $this->spyTelegram();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_step_x_complete_matrix_exact_rows_and_repaints(): void
    {
        $this->seedWorld();
        $cases = [];
        foreach (['pending', 'approved'] as $status) {
            foreach (['future' => '2026-11-16 10:00:01', 'equal' => self::NOW, 'past' => self::OLD] as $boundary => $expiry) {
                $cases[$status.'_'.$boundary] = $this->card($status, ['expires_at' => $expiry]);
            }
        }
        $cases['pushed'] = $this->card('approved', [], ['first_push_at' => self::OLD]);
        $cases['mixed_pending_log'] = $this->card('pending', ['status' => 'approved']);
        $cases['mixed_approved_log'] = $this->card('approved', ['status' => 'pending']);
        foreach (['sending', 'retry', 'sent', 'skipped', 'expired', 'cancelled'] as $status) {
            $cases[$status] = $this->card($status, ['status' => $status === 'retry' ? 'sending' : $status]);
        }
        $before = array_map($this->snapshot(...), $cases);
        $level = DB::transactionLevel();
        $this->fakeHttp(function ($request) use ($level): void {
            $this->assertSame($level, DB::transactionLevel(), 'repaint must be AFTER the per-row commit');
            $this->assertStringEndsWith('⌛ หมดอายุ ไม่ได้ส่ง', $request['text']);
            $this->assertSame(['inline_keyboard' => []], json_decode($request['reply_markup'], true));
        });

        $this->runJob();

        $closed = [];
        foreach ($cases as $name => $row) {
            if (in_array($name, ['pending_equal', 'pending_past', 'approved_equal', 'approved_past'], true)) {
                $this->assertExpired($row, $before[$name], str_starts_with($name, 'pending'));
                $closed[] = $row['approvalId'];
            } else {
                $this->assertSame($before[$name], $this->snapshot($row), $name);
            }
        }
        $this->assertSame($closed, $this->refreshIds);
        $this->assertSame(array_fill(0, 4, 'refreshed'), $this->refreshResults);
        $this->assertSame(array_fill(0, 4, 'editMessageText'), $this->methods());
        $this->assertInfo(4, 0);
        Queue::assertNothingPushed();
    }

    public function test_approved_close_matches_real_sender_without_changing_owner_audit(): void
    {
        $this->seedWorld();
        $closed = $this->card('approved');
        $twin = $this->card('approved');
        $before = $this->snapshot($closed);
        $this->assertSame('blocked', app(FollowUpSendService::class)->send($twin['approvalId'], $this->now()));
        $this->runJob();
        $this->assertExpired($closed, $before, false);
        $fields = ['delivery_status', 'reason_code', 'owner_action', 'owner_action_at', 'owner_telegram_user_id'];
        $this->assertSame(array_intersect_key($this->snapshot($twin)['log'], array_flip($fields)), array_intersect_key($this->snapshot($closed)['log'], array_flip($fields)));
        $this->assertSame($this->snapshot($twin)['approval']['status'], $this->snapshot($closed)['approval']['status']);
        $this->assertSame([$closed['approvalId']], $this->refreshIds);
        $this->assertSame(['editMessageText'], $this->methods());
    }

    public static function undeliveredStates(): array
    {
        return array_map(fn ($state) => [$state], ['new', 'failed', 'unknown', 'sending']);
    }

    #[DataProvider('undeliveredStates')]
    public function test_undelivered_cards_close_without_transport(string $state): void
    {
        $this->seedWorld();
        $row = $this->card('pending', ['telegram_delivery_state' => $state]);
        $before = $this->snapshot($row);
        $this->runJob();
        $this->assertExpired($row, $before, true);
        $this->assertSame([$row['approvalId']], $this->refreshIds);
        $this->assertSame(['blocked'], $this->refreshResults);
        Http::assertNothingSent();
    }

    public static function repaintFailures(): array
    {
        return [['500'], ['connection'], ['throw']];
    }

    #[DataProvider('repaintFailures')]
    public function test_repaint_failure_keeps_closed_rows_and_processes_next_without_retry(string $failure): void
    {
        $this->seedWorld();
        $first = $this->card();
        $second = $this->card();
        $before = [$this->snapshot($first), $this->snapshot($second)];
        if ($failure === 'throw') {
            $this->spyTelegram($first['approvalId']);
        } else {
            $n = 0;
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(function ($request) use ($failure, &$n) {
                $this->requests[] = $request;
                if (++$n === 1) {
                    if ($failure === 'connection') {
                        throw new ConnectionException(self::MARKER);
                    }

                    return Http::response(['ok' => false], 500);
                }

                return Http::response(['ok' => true, 'result' => true]);
            });
        }
        $this->runJob();
        $this->assertExpired($first, $before[0], true);
        $this->assertExpired($second, $before[1], true);
        $this->assertSame([$first['approvalId'], $second['approvalId']], $this->refreshIds);
        $this->assertSame(array_fill(0, $failure === 'throw' ? 1 : 2, 'editMessageText'), $this->methods());
        if ($failure === 'throw') {
            $this->assertWarnings([['approval_id' => $first['approvalId'], 'exception_class' => RuntimeException::class]]);
        } else {
            $this->assertSame(['unknown', 'refreshed'], $this->refreshResults);
            $this->assertWarnings([]);
        }
        $this->assertNoPrivateLogs();
        $this->resetObservations();
        $queries = $this->captureQueries();
        $this->runJob();
        $this->assertSelectOnly($queries);
        $this->assertSame([], $this->refreshIds);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame([], $this->jobLogs());
    }

    public function test_closed_rows_refuse_real_tap_edit_sender_and_scan_paths(): void
    {
        $this->seedWorld(true);
        $pending = $this->card('pending', ['edit_prompt_message_id' => 55, 'editor_telegram_user_id' => '555'], [], true);
        $approved = $this->card('approved');
        $this->runJob();
        $before = $this->snapshot($pending);
        $approvedBefore = $this->snapshot($approved);
        $this->resetObservations();
        $this->tap('fs', $pending['approvalId'])->assertOk();
        $this->assertSame(['answerCallbackQuery', 'editMessageText'], $this->methods());
        $this->assertSame('หมดอายุแล้ว ไม่ได้ส่ง', $this->requests[0]['text']);
        $this->assertStringEndsWith('⌛ หมดอายุ ไม่ได้ส่ง', $this->requests[1]['text']);
        $this->assertSame($before, $this->snapshot($pending));
        Queue::assertNothingPushed();

        $this->resetObservations();
        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $this->world['secret']])
            ->postJson('/api/webhook/telegram-alert/'.$this->world['token'], [
                'update_id' => 999002,
                'message' => [
                    'message_id' => 900, 'from' => ['id' => 555], 'chat' => ['id' => 888, 'type' => 'private'],
                    'reply_to_message' => ['message_id' => 55, 'from' => ['id' => 777000, 'is_bot' => true], 'chat' => ['id' => 888], 'text' => '✏️ แก้ข้อความ #'.$pending['logId']],
                    'text' => 'ข้อความที่เจ้านายแก้เองแล้ว',
                ],
            ])->assertOk();
        $this->assertSame(['sendMessage'], $this->methods());
        $this->assertSame('รายการนี้ปิดไปแล้ว แก้ข้อความไม่ได้', $this->requests[0]['text']);
        $this->assertSame($before, $this->snapshot($pending));

        $this->resetObservations();
        (new SendCustomerFollowUp($approved['approvalId']))->handle(app(FollowUpSendService::class), app(FollowUpTelegramService::class));
        $this->assertSame([], $this->methods());
        $this->assertSame($approvedBefore, $this->snapshot($approved));
        $count = DB::table('lead_recovery_logs')->count();
        $this->runScan();
        $this->assertSame($count, DB::table('lead_recovery_logs')->count());
        $this->assertSame($before, $this->snapshot($pending));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function xRaces(): array
    {
        return [
            'skip won' => ['pending', ['delivery_status' => 'skipped', 'owner_action' => 'skipped'], ['status' => 'skipped']],
            'sender claimed' => ['approved', ['delivery_status' => 'sending'], ['status' => 'sending']],
            'pending log changed' => ['pending', ['delivery_status' => 'cancelled'], []],
            'pending approval changed' => ['pending', [], ['status' => 'approved']],
            'approved log changed' => ['approved', ['delivery_status' => 'pending'], []],
            'approved approval changed' => ['approved', [], ['status' => 'pending']],
            'first push appeared' => ['approved', ['first_push_at' => self::OLD], []],
            'expiry changed' => ['pending', [], ['expires_at' => '2026-11-16 10:00:01']],
        ];
    }

    #[DataProvider('xRaces')]
    public function test_x_lost_race_rechecks_locked_rows_and_writes_nothing(string $pair, array $logChanges, array $approvalChanges): void
    {
        $this->seedWorld();
        $row = $this->card($pair);
        $racer = null;
        $this->afterList('follow_up_approvals', function () use ($row, $logChanges, $approvalChanges, &$racer): void {
            if ($logChanges !== []) {
                DB::table('lead_recovery_logs')->where('id', $row['logId'])->update($logChanges + ['updated_at' => '2026-11-16 09:59:59']);
            }
            if ($approvalChanges !== []) {
                DB::table('follow_up_approvals')->where('id', $row['approvalId'])->update($approvalChanges + ['updated_at' => '2026-11-16 09:59:59']);
            }
            $racer = $this->snapshot($row);
        });
        $this->runJob();
        $this->assertNotNull($racer, 'list-to-transaction seam must fire');
        $this->assertSame($racer, $this->snapshot($row));
        $this->assertSame([], $this->refreshIds);
        Http::assertNothingSent();
        $this->assertSame([], $this->jobLogs());
    }

    public function test_x_list_filters_before_limit_and_cannot_starve_a_due_high_id(): void
    {
        $this->seedWorld();
        $protected = [];
        for ($i = 0; $i < 50; $i++) {
            [$status, $approval, $log] = match ($i % 5) {
                0 => ['pending', ['expires_at' => '2026-11-16 10:00:01'], []],
                1 => ['pending', ['status' => 'approved'], []],
                2 => ['approved', ['status' => 'pending'], []],
                3 => ['approved', [], ['first_push_at' => self::OLD]],
                4 => ['sending', ['status' => 'sending'], []],
            };
            $row = $this->card($status, $approval, $log);
            $protected[] = [$row, $this->snapshot($row)];
        }
        $due = $this->card();
        $before = $this->snapshot($due);
        $this->runJob();
        $this->assertExpired($due, $before, true);
        $this->assertSame([$due['approvalId']], $this->refreshIds);
        foreach ($protected as [$row, $snapshot]) {
            $this->assertSame($snapshot, $this->snapshot($row));
        }
    }

    public function test_x_limit_is_fifty_lowest_approval_ids_then_next_run(): void
    {
        $this->seedWorld();
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = $this->card($i % 2 ? 'approved' : 'pending');
        }
        $before = array_map($this->snapshot(...), $rows);
        $this->runJob();
        $this->assertSame(array_column(array_slice($rows, 0, 50), 'approvalId'), $this->refreshIds);
        for ($i = 0; $i < 50; $i++) {
            $this->assertExpired($rows[$i], $before[$i], $i % 2 === 0);
        }
        $this->assertSame($before[50], $this->snapshot($rows[50]));
        $this->assertInfo(50, 0);
        $this->resetObservations();
        $this->runJob();
        $this->assertSame([$rows[50]['approvalId']], $this->refreshIds);
        $this->assertExpired($rows[50], $before[50], true);
        $this->assertInfo(1, 0);
    }

    public function test_y_complete_matrix_releases_daily_slot_preserves_produced_draft_and_blocks_prepare(): void
    {
        $this->seedWorld(true);
        $cases = [
            'candidate_old' => $this->waiting('candidate', ['created_at' => '2026-11-09 09:59:59'], true),
            'candidate_equal' => $this->waiting('candidate', ['created_at' => '2026-11-09 10:00:00']),
            'retry' => $this->waiting('sort_retry'),
            'deferred' => $this->waiting('deferred'),
            'sorting_stale' => $this->waiting('sorting', ['updated_at' => '2026-11-16 09:50:00']),
            'sorting_fresh' => $this->waiting('sorting', ['updated_at' => '2026-11-16 09:50:01']),
            'drafting_stale' => $this->waiting('drafting', ['draft_day' => '2026-11-16']),
            'drafting_fresh' => $this->waiting('drafting', ['updated_at' => '2026-11-16 09:50:01']),
            'produced' => $this->waiting('drafting', ['drafted_at' => self::OLD]),
            'candidate_recent' => $this->waiting('candidate', ['created_at' => '2026-11-10 10:00:00']),
        ];
        foreach (['pending', 'approved', 'excluded', 'sent'] as $status) {
            $cases[$status] = $this->waiting($status);
        }
        $before = array_map($this->snapshot(...), $cases);
        $this->assertSame(1, $this->dailyCount());
        $this->runJob();
        foreach ($cases as $name => $row) {
            if (in_array($name, ['candidate_old', 'retry', 'deferred', 'sorting_stale', 'drafting_stale', 'produced'], true)) {
                $this->assertWaitingClosed($row, $before[$name]);
            } else {
                $this->assertSame($before[$name], $this->snapshot($row), $name);
            }
        }
        $this->assertSame(0, $this->dailyCount());
        $this->assertSame([], $this->refreshIds);
        Http::assertNothingSent();
        $this->assertInfo(0, 6);
        $closed = $cases['candidate_old'];
        $closedBefore = $this->snapshot($closed);
        app(FollowUpPipeline::class)->prepare($closed['logId'], $this->now());
        $this->assertSame($closedBefore, $this->snapshot($closed));
        // Only the anchored closed silence is eligible for scan; other waiting fixtures have no anchor.
        $this->runScan();
        $this->assertSame(1, DB::table('lead_recovery_logs')->where('silence_user_message_id', $this->world['anchorId'])->count());
        Http::assertNothingSent();
    }

    public static function yRaces(): array
    {
        return [
            'pipeline claimed' => [['delivery_status' => 'sorting', 'updated_at' => self::NOW]],
            'status changed' => [['delivery_status' => 'pending']],
            'created at changed' => [['created_at' => '2026-11-09 10:00:00']],
        ];
    }

    #[DataProvider('yRaces')]
    public function test_y_lost_race_rechecks_locked_row(array $changes): void
    {
        $this->seedWorld();
        $row = $this->waiting('candidate');
        $racer = null;
        $this->afterList('lead_recovery_logs', function () use ($row, $changes, &$racer): void {
            DB::table('lead_recovery_logs')->where('id', $row['logId'])->update($changes);
            $racer = $this->snapshot($row);
        });
        $this->runJob();
        $this->assertNotNull($racer);
        $this->assertSame($racer, $this->snapshot($row));
        $this->assertSame([], $this->refreshIds);
        Http::assertNothingSent();
    }

    public function test_y_list_filters_before_limit(): void
    {
        $this->seedWorld();
        $protected = [];
        for ($i = 0; $i < 100; $i++) {
            [$status, $changes] = match ($i % 5) {
                0 => ['candidate', ['created_at' => '2026-11-09 10:00:00']],
                1 => ['pending', []],
                2 => ['sorting', ['updated_at' => '2026-11-16 09:50:01']],
                3 => ['drafting', ['updated_at' => '2026-11-16 09:50:01']],
                4 => ['approved', []],
            };
            $row = $this->waiting($status, $changes);
            $protected[] = [$row, $this->snapshot($row)];
        }
        $due = $this->waiting('candidate');
        $before = $this->snapshot($due);
        $this->runJob();
        $this->assertWaitingClosed($due, $before);
        foreach ($protected as [$row, $snapshot]) {
            $this->assertSame($snapshot, $this->snapshot($row));
        }
        $this->assertInfo(0, 1);
    }

    public function test_y_limit_is_one_hundred_lowest_ids_then_next_run(): void
    {
        $this->seedWorld();
        $rows = [];
        for ($i = 0; $i < 101; $i++) {
            $rows[] = $this->waiting('candidate');
        }
        $before = array_map($this->snapshot(...), $rows);
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (str_starts_with($query->sql, 'update "lead_recovery_logs"') && in_array('waited_too_long', $query->bindings, true)) {
                $writes[] = (int) end($query->bindings);
            }
        });
        $this->runJob();
        $this->assertSame(array_column(array_slice($rows, 0, 100), 'logId'), $writes);
        for ($i = 0; $i < 100; $i++) {
            $this->assertWaitingClosed($rows[$i], $before[$i]);
        }
        $this->assertSame($before[100], $this->snapshot($rows[100]));
        $this->resetObservations();
        $this->runJob();
        $this->assertWaitingClosed($rows[100], $before[100]);
        $this->assertInfo(0, 1);
    }

    public function test_outcomes_called_once_after_both_closing_steps_at_the_same_instant(): void
    {
        $this->seedWorld();
        $card = $this->card();
        $waiting = $this->waiting('candidate');
        $this->mock(FollowUpOutcomeService::class, function (MockInterface $mock) use ($card, $waiting): void {
            $mock->shouldReceive('reconcile')->once()->andReturnUsing(function (CarbonImmutable $now) use ($card, $waiting): void {
                $this->assertSame(self::NOW, $now->format('Y-m-d H:i:s'));
                $this->assertSame('Asia/Bangkok', $now->timezoneName);
                $this->assertSame('expired', $this->snapshot($card)['log']['delivery_status']);
                $this->assertSame('excluded', $this->snapshot($waiting)['log']['delivery_status']);
                $this->assertSame([$card['approvalId']], $this->refreshIds);
            });
        });
        $this->runJob();
        $this->assertInfo(1, 1);
    }

    public function test_real_outcomes_close_only_after_reply_and_order_grace_boundaries(): void
    {
        $this->seedWorld();
        DB::table('messages')->where('conversation_id', $this->world['room']->id)->update(['created_at' => '2026-11-01 10:00:00']);
        $reply = $this->waiting('sent', ['sent_at' => '2026-11-13 09:50:01']);
        $order = $this->waiting('sent', ['sent_at' => '2026-11-09 09:50:01', 'replied_within_72h' => true, 'response_window_closed_at' => self::OLD]);
        $this->runJob();
        $this->assertNull($this->snapshot($reply)['log']['replied_within_72h']);
        $this->assertNull($this->snapshot($order)['log']['ordered_within_7d']);
        $this->freeze('2026-11-16 10:00:01');
        $this->runJob();
        $replyAfter = $this->snapshot($reply)['log'];
        $orderAfter = $this->snapshot($order)['log'];
        $this->assertFalse((bool) $replyAfter['replied_within_72h']);
        $this->assertNotNull($replyAfter['replied_within_72h']);
        $this->assertSame('2026-11-16 10:00:01', $replyAfter['response_window_closed_at']);
        $this->assertNull($replyAfter['ordered_within_7d']);
        $this->assertFalse((bool) $orderAfter['ordered_within_7d']);
        $this->assertNotNull($orderAfter['ordered_within_7d']);
        $this->assertSame('2026-11-16 10:00:01', $orderAfter['order_window_closed_at']);
        $this->assertTrue((bool) $orderAfter['replied_within_72h']);
        $this->assertSame(self::OLD, $orderAfter['response_window_closed_at']);
        Http::assertNothingSent();
    }

    public function test_outcome_failure_is_swallowed_class_only_after_cards_are_closed(): void
    {
        $this->seedWorld();
        $row = $this->card();
        $before = $this->snapshot($row);
        $this->mock(FollowUpOutcomeService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')->once()->andThrow(new RuntimeException(self::MARKER));
        });
        $this->runJob();
        $this->assertExpired($row, $before, true);
        $this->assertSame([$row['approvalId']], $this->refreshIds);
        $this->assertWarnings([['exception_class' => RuntimeException::class]]);
        $this->assertInfo(1, 0, true);
        $this->assertNoPrivateLogs();
    }

    public static function transactionFailures(): array
    {
        return [['card'], ['waiting']];
    }

    #[DataProvider('transactionFailures')]
    public function test_one_transaction_rolls_back_and_warns_without_stopping_other_rows_or_outcomes(string $step): void
    {
        $this->seedWorld();
        $bad = $step === 'card' ? $this->card() : $this->waiting('candidate');
        $card = $this->card();
        $waiting = $this->waiting('candidate');
        $before = $this->snapshot($bad);
        $fired = false;
        DB::listen(function (QueryExecuted $query) use ($bad, $step, &$fired): void {
            $table = $step === 'card' ? 'follow_up_approvals' : 'lead_recovery_logs';
            $id = $step === 'card' ? $bad['approvalId'] : $bad['logId'];
            if (! $fired && str_starts_with($query->sql, 'update "'.$table.'"') && (int) end($query->bindings) === $id) {
                $fired = true;
                throw new RuntimeException(self::MARKER);
            }
        });
        $this->mock(FollowUpOutcomeService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')->once()->withArgs(fn (CarbonImmutable $now) => $now->format('Y-m-d H:i:s') === self::NOW);
        });
        $this->runJob();
        $this->assertTrue($fired);
        $this->assertSame($before, $this->snapshot($bad), 'the entire failed transaction rolls back');
        $this->assertSame('expired', $this->snapshot($card)['log']['delivery_status']);
        $this->assertSame('excluded', $this->snapshot($waiting)['log']['delivery_status']);
        $this->assertSame([$card['approvalId']], $this->refreshIds);
        $this->assertWarnings([[$step === 'card' ? 'approval_id' : 'log_id' => $step === 'card' ? $bad['approvalId'] : $bad['logId'], 'exception_class' => RuntimeException::class]]);
        $this->assertInfo(1, 1);
        $this->assertNoPrivateLogs();
    }

    public function test_clock_advancing_during_first_repaint_does_not_change_later_writes_or_outcomes(): void
    {
        $this->seedWorld();
        $first = $this->card();
        $second = $this->card('approved');
        $waiting = $this->waiting('candidate');
        $before = array_map($this->snapshot(...), [$first, $second, $waiting]);
        $this->freeze('2026-11-16 10:00:00.987654');
        $this->fakeHttp(function (): void {
            $this->freeze('2026-11-16 12:00:00');
        });
        $this->mock(FollowUpOutcomeService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reconcile')->once()->withArgs(fn (CarbonImmutable $now) => $now->format('Y-m-d H:i:s.u') === self::NOW.'.000000');
        });
        $this->runJob();
        $this->assertExpired($first, $before[0], true);
        $this->assertExpired($second, $before[1], false);
        $this->assertWaitingClosed($waiting, $before[2]);
        $this->assertSame([$first['approvalId'], $second['approvalId']], $this->refreshIds);
    }

    public function test_empty_run_is_select_only_silent_and_control_due_card_acts(): void
    {
        $queries = $this->captureQueries();
        $this->runJob();
        $this->assertSelectOnly($queries);
        $this->assertCount(3, $queries, 'X list, Y list, Z open sent rows');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertSame([], $this->refreshIds);
        $this->assertSame([], $this->jobLogs());
        $this->seedWorld();
        $row = $this->card();
        $before = $this->snapshot($row);
        $this->runJob();
        $this->assertExpired($row, $before, true);
        $this->assertSame([$row['approvalId']], $this->refreshIds);
        $this->assertSame(['editMessageText'], $this->methods());
        $this->assertInfo(1, 0);
        $this->assertNoPrivateLogs();
    }

    public function test_successful_second_run_is_select_only_without_repaint_queue_or_info(): void
    {
        $this->seedWorld();
        $this->card();
        $this->waiting('candidate');
        $this->runJob();
        $this->resetObservations();
        $queries = $this->captureQueries();
        $this->runJob();
        $this->assertSelectOnly($queries);
        $this->assertSame([], $this->refreshIds);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame([], $this->jobLogs());
    }

    public function test_job_metadata_and_empty_serialization(): void
    {
        $job = new ReconcileCustomerFollowUps;
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(1, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertNull($job->queue);
        $this->assertSame([], $job->__serialize());
        $this->assertEquals($job, unserialize(serialize($job)));
    }

    public function test_schedule_registers_exactly_one_five_minute_job_with_short_mutex(): void
    {
        $this->artisan('schedule:list')->assertExitCode(0);
        $events = array_values(array_filter(app(Schedule::class)->events(), fn (ScheduleEvent $event): bool => $event->getSummaryForDisplay() === 'customer-follow-up-reconcile'));
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(5, $event->expiresAt);
        $callback = (new ReflectionProperty($event, 'callback'))->getValue($event);
        $job = (new ReflectionFunction($callback))->getStaticVariables()['job'];
        $this->assertInstanceOf(ReconcileCustomerFollowUps::class, $job);
        $event->run(app(Container::class));
        Queue::assertPushed(ReconcileCustomerFollowUps::class, 1);
    }

    private function seedWorld(bool $open = false): void
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id, 'name' => 'Line - Adsvance', 'primary_chat_model' => 'openai/gpt-6-luna',
            'channel_access_token' => implode('', ['fake', 'Line', 'Token', 'B6f', '777']),
        ]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        $token = 'tg-world-'.'b6f-'.md5((string) $bot->id);
        $secret = 'webhook-secret-'.'b6f';
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id, 'type' => 'telegram', 'name' => 'แจ้งเตือน', 'enabled' => true, 'trigger_condition' => 'always',
            'config' => ['access_token' => $token, 'chat_id' => '888', 'authorized_user_ids' => ['555']],
        ]);
        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id, 'follow_up_enabled' => $open, 'follow_up_mode' => 'approve',
            'follow_up_started_at' => '2026-10-17 10:00:00', 'follow_up_activation_message_id' => 0,
            'follow_up_activated_at' => '2026-10-17 10:00:00', 'created_at' => self::OLD, 'updated_at' => self::OLD,
        ]);
        $room = Conversation::factory()->line()->create(['bot_id' => $bot->id, 'external_customer_id' => 'Ub6f000112233445566778899aabbccdd']);
        foreach (['สนใจ Nolimit Personal ครับ', 'ต้องการกี่ตัวครับ', 'เอา 2 ตัวครับ', 'ได้ครับ กำลังเตรียมสินค้าให้ครับ', 'ประเมินราคาให้หน่อยครับ'] as $i => $text) {
            $anchorId = DB::table('messages')->insertGetId([
                'conversation_id' => $room->id, 'sender' => $i % 2 === 0 ? 'user' : 'bot', 'type' => 'text', 'content' => $text,
                'created_at' => '2026-11-15 08:5'.(5 + $i).':00', 'updated_at' => self::OLD,
            ]);
        }
        FollowUpSalesProjector::refresh($room, $this->now());
        config(['services.telegram_alert.secret' => $secret, 'customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        if ($open) {
            config(['customer_follow_up.bot_ids' => [$bot->id]]);
        }
        $this->world = compact('bot', 'room', 'plugin', 'token', 'secret', 'anchorId');
    }

    private function card(string $status = 'pending', array $approval = [], array $log = [], bool $anchored = false): array
    {
        $logId = $this->insertLog($status, array_merge([
            'draft' => self::TEXT, 'drafted_at' => '2026-11-15 09:00:00', 'draft_day' => '2026-11-15',
            'check_answers' => self::CHECK,
            'owner_action' => $status === 'approved' ? 'sent' : null,
            'owner_action_at' => $status === 'approved' ? self::OLD : null,
            'owner_telegram_user_id' => $status === 'approved' ? '555' : null,
        ], $log), $anchored);
        $approvalId = (int) DB::table('follow_up_approvals')->insertGetId(array_merge([
            'lead_recovery_log_id' => $logId, 'status' => $status, 'revision' => 1, 'nonce' => self::NONCE,
            'text' => self::TEXT, 'check_answers' => self::CHECK, 'expires_at' => self::NOW,
            'telegram_plugin_id' => $this->world['plugin']->id, 'telegram_delivery_state' => 'sent',
            'telegram_chat_id' => '888', 'telegram_message_id' => 77, 'created_at' => self::OLD, 'updated_at' => self::OLD,
        ], $approval));

        return compact('logId', 'approvalId');
    }

    private function waiting(string $status, array $overrides = [], bool $anchored = false): array
    {
        return ['logId' => $this->insertLog($status, array_merge([
            'created_at' => '2026-11-08 10:00:00', 'updated_at' => self::OLD,
            'next_attempt_at' => '2026-11-17 10:00:00',
            'claim_token' => '9f8a7c6d'.'-5e4b-4a3c'.'-9d2e-1b0a7c6d5e4f', 'claimed_at' => self::OLD,
            'draft_reserved_at' => self::OLD, 'draft_day' => '2026-11-15',
        ], $overrides), $anchored)];
    }

    private function insertLog(string $status, array $overrides, bool $anchored): int
    {
        return (int) DB::table('lead_recovery_logs')->insertGetId(array_merge([
            'conversation_id' => $this->world['room']->id, 'bot_id' => $this->world['bot']->id,
            'attempt_number' => 0, 'attempt_count' => 2, 'message_mode' => 'ai', 'delivery_status' => $status,
            'silence_user_message_id' => $anchored ? $this->world['anchorId'] : null,
            'observed_last_message_id' => $this->world['anchorId'],
            'customer_key' => hash('sha256', $this->world['bot']->id.'|line|'.$this->world['room']->external_customer_id),
            'reason_code' => 'before_close', 'created_at' => self::OLD, 'updated_at' => self::OLD,
        ], $overrides));
    }

    private function snapshot(array $row): array
    {
        $snapshot = ['log' => (array) DB::table('lead_recovery_logs')->where('id', $row['logId'])->first()];
        if (isset($row['approvalId'])) {
            $snapshot['approval'] = (array) DB::table('follow_up_approvals')->where('id', $row['approvalId'])->first();
        }

        return $snapshot;
    }

    private function assertExpired(array $row, array $before, bool $pending): void
    {
        $changes = ['delivery_status' => 'expired', 'reason_code' => $pending ? 'expired_unanswered' : 'expired_before_send', 'updated_at' => self::NOW];
        if ($pending) {
            $changes += ['owner_action' => 'expired', 'owner_action_at' => self::NOW];
        }
        $after = $this->snapshot($row);
        $this->assertExactChange($before['log'], $after['log'], $changes);
        $this->assertExactChange($before['approval'], $after['approval'], ['status' => 'expired', 'updated_at' => self::NOW]);
    }

    private function assertWaitingClosed(array $row, array $before): void
    {
        $changes = ['delivery_status' => 'excluded', 'reason_code' => 'waited_too_long', 'next_attempt_at' => null, 'claim_token' => null, 'claimed_at' => null, 'updated_at' => self::NOW];
        if ($before['log']['drafted_at'] === null) {
            $changes += ['draft_reserved_at' => null, 'draft_day' => null];
        }
        $this->assertExactChange($before['log'], $this->snapshot($row)['log'], $changes);
    }

    private function assertExactChange(array $before, array $after, array $changes): void
    {
        foreach ($changes as $key => $value) {
            $this->assertNotSame($value, $before[$key], 'fixture must distinguish omitted write: '.$key);
        }
        $this->assertSame(array_replace($before, $changes), $after, 'all unlisted columns must be byte-equal');
    }

    private function spyTelegram(?int $throwId = null): void
    {
        // Capture a real, unmocked service even when replacing an earlier spy.
        $real = $this->app->build(FollowUpTelegramService::class);
        $this->mock(FollowUpTelegramService::class, function (MockInterface $mock) use ($real, $throwId): void {
            $mock->shouldReceive('refreshCard')->andReturnUsing(function (int $id, ?CarbonImmutable $now = null) use ($real, $throwId): string {
                $this->refreshIds[] = $id;
                $this->assertSame(self::NOW, $now?->format('Y-m-d H:i:s'));
                if ($id === $throwId) {
                    throw new RuntimeException(self::MARKER);
                }
                $result = $real->refreshCard($id, $now);
                $this->refreshResults[] = $result;

                return $result;
            });
        });
    }

    private function fakeHttp(?callable $inspect = null): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($inspect) {
            $this->assertSame('api.telegram.org', parse_url($request->url(), PHP_URL_HOST), 'no LINE/model call is allowed');
            $this->requests[] = $request;
            if ($inspect !== null) {
                $inspect($request);
            }

            return Http::response(['ok' => true, 'result' => true]);
        });
    }

    private function resetObservations(): void
    {
        $this->requests = $this->refreshIds = $this->refreshResults = $this->logs = [];
        $this->fakeHttp();
    }

    private function runJob(): void
    {
        (new ReconcileCustomerFollowUps)->handle(app(FollowUpTelegramService::class), app(FollowUpOutcomeService::class));
    }

    private function runScan(): void
    {
        (new ProcessCustomerFollowUps)->handle(app(FollowUpReservationService::class), app(FollowUpTelegramService::class));
    }

    private function methods(): array
    {
        return array_map(fn ($request) => basename(parse_url($request->url(), PHP_URL_PATH)), $this->requests);
    }

    private function tap(string $code, int $id): TestResponse
    {
        return $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => $this->world['secret']])
            ->postJson('/api/webhook/telegram-alert/'.$this->world['token'], [
                'update_id' => 999001,
                'callback_query' => ['id' => 'cb-canary-b6f', 'from' => ['id' => 555], 'message' => ['message_id' => 77, 'chat' => ['id' => 888]], 'data' => $code.'|'.$id.'|1|'.self::NONCE],
            ]);
    }

    private function afterList(string $table, callable $race): void
    {
        $fired = false;
        DB::listen(function (QueryExecuted $query) use ($table, $race, &$fired): void {
            if (! $fired && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "'.$table.'"') && ! str_contains($query->sql, 'select *')) {
                $fired = true;
                $race();
            }
        });
    }

    private function captureQueries(): \ArrayObject
    {
        $queries = new \ArrayObject;
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries[] = $query->sql;
        });

        return $queries;
    }

    private function assertSelectOnly(\ArrayObject $queries): void
    {
        $this->assertNotEmpty($queries->getArrayCopy());
        foreach ($queries as $sql) {
            $this->assertStringStartsWith('select', strtolower($sql));
        }
    }

    private function dailyCount(): int
    {
        return DB::table('lead_recovery_logs')->where('bot_id', $this->world['bot']->id)->where('draft_day', '2026-11-16')
            ->where(fn ($query) => $query->whereNotNull('drafted_at')->orWhereNotNull('draft_reserved_at'))->count();
    }

    private function jobLogs(): array
    {
        return array_values(array_filter($this->logs, fn ($event) => str_starts_with($event->message, 'Follow-up reconcile')));
    }

    private function assertInfo(int $cards, int $waiting, bool $failed = false): void
    {
        $events = array_values(array_filter($this->jobLogs(), fn ($event) => $event->level === 'info'));
        $this->assertCount(1, $events);
        $this->assertSame('Follow-up reconcile', $events[0]->message);
        $context = $events[0]->context;
        $this->assertSame(['cards_expired', 'waiting_closed', 'outcomes_failed', 'milliseconds'], array_keys($context));
        $this->assertSame($cards, $context['cards_expired']);
        $this->assertSame($waiting, $context['waiting_closed']);
        $this->assertSame($failed, $context['outcomes_failed']);
        $this->assertIsInt($context['milliseconds']);
        $this->assertGreaterThanOrEqual(0, $context['milliseconds']);
    }

    private function assertWarnings(array $contexts): void
    {
        $warnings = array_values(array_filter($this->jobLogs(), fn ($event) => $event->level === 'warning'));
        $this->assertSame($contexts, array_map(fn ($event) => $event->context, $warnings));
    }

    private function assertNoPrivateLogs(): void
    {
        $text = json_encode(array_map(fn ($event) => [$event->message, $event->context], $this->logs), JSON_UNESCAPED_UNICODE);
        foreach ([self::TEXT, self::MARKER, $this->world['room']->external_customer_id, $this->world['token'], $this->world['secret'], $this->world['bot']->channel_access_token] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::NOW, 'Asia/Bangkok');
    }

    private function freeze(string $when): void
    {
        Carbon::setTestNow(CarbonImmutable::parse($when, 'Asia/Bangkok'));
    }
}
