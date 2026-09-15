<?php

namespace Tests\Feature\CommerceSafety;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\Message;
use App\Models\Order;
use App\Models\SlipVerification;
use App\Models\User;
use App\Models\VerifiedPaymentEvent;
use App\Services\CommerceSafety\MoneyMinor;
use App\Services\CommerceSafety\PaymentProofService;
use App\Services\CommerceSafety\SafetyScope;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

class PaymentProofTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Bot $bot;

    private Conversation $conversation;

    private Message $receipt;

    private SlipVerification $slip;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->owner = User::factory()->owner()->create();
        $this->bot = Bot::factory()->active()->create(['user_id' => $this->owner->id]);
        $this->conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $this->receipt = $this->conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'เงินเข้าแล้ว 199.01 บาท',
        ]);
        $this->slip = SlipVerification::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => null,
            'trans_ref' => 'TXN-PROOF-001',
            'amount' => '199.01',
            'receiver_account' => 'xxx-x-x4880-x',
            'status' => 'passed',
            'raw_response' => ['provider' => 'fixture'],
        ]);

        $flow = Flow::factory()->create(['bot_id' => $this->bot->id]);
        $plugin = FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'order',
            'name' => 'Payment fixture',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => [],
        ]);

        config(["commerce_safety.bots.{$this->bot->id}" => [
            'mode' => 'enforce',
            'payment_plugin_ids' => [$plugin->id],
        ]]);
    }

    public function test_unconfigured_bots_are_off_and_unknown_modes_fail_closed(): void
    {
        $unconfigured = new Bot;
        $unconfigured->id = 27;

        $this->assertSame('off', app(SafetyScope::class)->mode($unconfigured));

        config(["commerce_safety.bots.{$this->bot->id}.mode" => 'unexpected']);

        $this->assertSame('hold', app(SafetyScope::class)->mode($this->bot));
    }

    public function test_known_modes_are_returned_for_a_valid_payment_plugin_scope(): void
    {
        foreach (['off', 'shadow', 'enforce', 'hold'] as $mode) {
            config(["commerce_safety.bots.{$this->bot->id}.mode" => $mode]);

            $this->assertSame($mode, app(SafetyScope::class)->mode($this->bot));
        }
    }

    public function test_payment_plugin_mismatch_blocks_activation(): void
    {
        config(["commerce_safety.bots.{$this->bot->id}" => [
            'mode' => 'enforce',
            'payment_plugin_ids' => [999999],
        ]]);

        $this->assertSame('hold', app(SafetyScope::class)->mode($this->bot));
    }

    public function test_money_minor_parses_plain_thb_decimals_exactly(): void
    {
        $cases = [
            '0' => 0,
            '199' => 19900,
            '199.01' => 19901,
            '0.05' => 5,
        ];

        foreach ($cases as $amount => $expected) {
            $this->assertSame($expected, MoneyMinor::fromDecimal($amount));
        }
    }

    public function test_money_minor_rejects_non_plain_or_overprecise_amounts(): void
    {
        foreach (['1.001', '-1', '+1', '1e2', '1,000', '01', '.50', '1.'] as $amount) {
            try {
                MoneyMinor::fromDecimal($amount);
                $this->fail("Expected [{$amount}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_money_minor_rejects_integer_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MoneyMinor::fromDecimal((string) PHP_INT_MAX);
    }

    public function test_message_metadata_alone_is_not_payment_proof(): void
    {
        $forgedReceipt = $this->conversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'เงินเข้าแล้ว 1 บาท',
            'metadata' => ['slip_verification' => true, 'slip_status' => 'passed'],
        ]);

        $this->assertNull(app(PaymentProofService::class)
            ->forReceipt($this->bot, $this->conversation, $forgedReceipt));
        Http::assertNothingSent();
    }

    public function test_automatic_pass_records_a_relation_backed_payment_event(): void
    {
        $event = app(PaymentProofService::class)
            ->record($this->bot, $this->conversation, $this->slip, $this->receipt, null);

        $this->assertSame('easyslip', $event->source);
        $this->assertSame('easyslip:TXN-PROOF-001', $event->event_key);
        $this->assertSame('THB', $event->currency);
        $this->assertSame(19901, $event->amount_minor);
        $this->assertNull($event->actor_id);
        $this->assertNull($event->order_id);
        $this->assertSame($event->id, app(PaymentProofService::class)
            ->forReceipt($this->bot, $this->conversation, $this->receipt)?->id);
        $this->assertDatabaseHas('verified_payment_events', [
            'id' => $event->id,
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'slip_verification_id' => $this->slip->id,
            'receipt_message_id' => $this->receipt->id,
            'amount_minor' => 19901,
        ]);
        Http::assertNothingSent();
    }

    public function test_repeated_event_key_returns_the_same_event(): void
    {
        $service = app(PaymentProofService::class);

        $first = $service->record(
            $this->bot, $this->conversation, $this->slip, $this->receipt, null,
        );
        $second = $service->record(
            $this->bot, $this->conversation, $this->slip, $this->receipt, null,
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, VerifiedPaymentEvent::count());
    }

    public function test_authority_mutated_when_record_transaction_begins_cannot_create_an_event(): void
    {
        $transactionMutatedAuthority = false;

        Event::listen(TransactionBeginning::class, function () use (&$transactionMutatedAuthority): void {
            if ($transactionMutatedAuthority) {
                return;
            }

            $transactionMutatedAuthority = true;
            DB::table('slip_verifications')
                ->where('id', $this->slip->id)
                ->update(['status' => 'fake']);
        });

        try {
            app(PaymentProofService::class)->record(
                $this->bot, $this->conversation, $this->slip, $this->receipt, null,
            );
            $this->fail('Payment proof was created from authority mutated at transaction start.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue($transactionMutatedAuthority);
        $this->assertSame(0, VerifiedPaymentEvent::count());
    }

    public function test_a_receipt_cannot_be_reused_for_a_different_payment_event(): void
    {
        $service = app(PaymentProofService::class);
        $service->record(
            $this->bot, $this->conversation, $this->slip, $this->receipt, null,
        );
        $secondSlip = SlipVerification::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'trans_ref' => 'TXN-PROOF-002',
            'amount' => '1.00',
            'status' => 'passed',
        ]);

        $this->expectException(ValidationException::class);
        $service->record(
            $this->bot, $this->conversation, $secondSlip, $this->receipt, null,
        );
    }

    public function test_manual_confirmation_requires_and_records_the_authorized_owner(): void
    {
        $manualSlip = SlipVerification::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->receipt->id,
            'trans_ref' => null,
            'amount' => '1.00',
            'receiver_account' => 'xxx-x-x4880-x',
            'status' => 'manual_confirmed',
            'raw_response' => null,
        ]);

        $event = app(PaymentProofService::class)->record(
            $this->bot, $this->conversation, $manualSlip, $this->receipt, $this->owner->id,
        );

        $this->assertSame('manual', $event->source);
        $this->assertSame("manual-slip:{$manualSlip->id}", $event->event_key);
        $this->assertSame(100, $event->amount_minor);
        $this->assertSame($this->owner->id, $event->actor_id);
    }

    public function test_manual_confirmation_rejects_a_missing_or_unauthorized_actor(): void
    {
        $manualSlip = SlipVerification::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->receipt->id,
            'trans_ref' => null,
            'amount' => '1.00',
            'status' => 'manual_confirmed',
        ]);

        try {
            app(PaymentProofService::class)->record(
                $this->bot, $this->conversation, $manualSlip, $this->receipt, null,
            );
            $this->fail('A manual proof without an actor was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $unrelatedOwner = User::factory()->owner()->create();

        $this->expectException(ValidationException::class);
        app(PaymentProofService::class)->record(
            $this->bot, $this->conversation, $manualSlip, $this->receipt, $unrelatedOwner->id,
        );
    }

    public function test_failed_or_trans_ref_less_automatic_slips_cannot_create_proof(): void
    {
        $this->slip->update(['status' => 'fake']);

        try {
            app(PaymentProofService::class)->record(
                $this->bot, $this->conversation, $this->slip, $this->receipt, null,
            );
            $this->fail('A failed slip was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->slip->update(['status' => 'passed', 'trans_ref' => '   ']);

        $this->expectException(ValidationException::class);
        app(PaymentProofService::class)->record(
            $this->bot, $this->conversation, $this->slip, $this->receipt, null,
        );
    }

    public function test_cross_bot_or_cross_conversation_rows_cannot_create_proof(): void
    {
        $otherOwner = User::factory()->owner()->create();
        $otherBot = Bot::factory()->active()->make(['user_id' => $otherOwner->id]);
        $otherBot->id = 27;
        $otherBot->save();
        $otherConversation = Conversation::factory()->create(['bot_id' => $otherBot->id]);
        $otherReceipt = $otherConversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'receipt',
        ]);

        try {
            app(PaymentProofService::class)->record(
                $otherBot, $otherConversation, $this->slip, $otherReceipt, null,
            );
            $this->fail('A cross-bot slip was accepted.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $sameBotOtherConversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $sameBotOtherReceipt = $sameBotOtherConversation->messages()->create([
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'receipt',
        ]);

        $this->expectException(ValidationException::class);
        app(PaymentProofService::class)->record(
            $this->bot, $sameBotOtherConversation, $this->slip, $sameBotOtherReceipt, null,
        );
    }

    public function test_unsaved_rows_cannot_create_or_resolve_proof(): void
    {
        $unsavedReceipt = new Message([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'type' => 'text',
            'content' => 'receipt',
        ]);

        $this->assertNull(app(PaymentProofService::class)
            ->forReceipt($this->bot, $this->conversation, $unsavedReceipt));

        $this->expectException(ValidationException::class);
        app(PaymentProofService::class)->record(
            $this->bot, $this->conversation, $this->slip, $unsavedReceipt, null,
        );
    }

    public function test_customer_sender_cannot_be_used_as_the_receipt(): void
    {
        $customerMessage = $this->conversation->messages()->create([
            'sender' => 'user',
            'type' => 'text',
            'content' => 'I paid',
        ]);

        $this->expectException(ValidationException::class);
        app(PaymentProofService::class)->record(
            $this->bot, $this->conversation, $this->slip, $customerMessage, null,
        );
    }

    public function test_for_receipt_requires_matching_bot_and_conversation_relations(): void
    {
        app(PaymentProofService::class)
            ->record($this->bot, $this->conversation, $this->slip, $this->receipt, null);

        $otherConversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
        $otherOwner = User::factory()->owner()->create();
        $otherBot = Bot::factory()->active()->make(['user_id' => $otherOwner->id]);
        $otherBot->id = 27;
        $otherBot->save();

        $service = app(PaymentProofService::class);

        $this->assertNull($service->forReceipt($this->bot, $otherConversation, $this->receipt));
        $this->assertNull($service->forReceipt($otherBot, $this->conversation, $this->receipt));
    }

    public function test_payment_events_reject_mass_assignment(): void
    {
        $this->expectException(MassAssignmentException::class);

        VerifiedPaymentEvent::create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
        ]);
    }

    public function test_persisted_payment_events_cannot_be_updated_or_deleted(): void
    {
        $event = app(PaymentProofService::class)
            ->record($this->bot, $this->conversation, $this->slip, $this->receipt, null);

        try {
            $event->currency = 'USD';
            $event->save();
            $this->fail('A verified payment event was updated.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $event->delete();
            $this->fail('A verified payment event was deleted.');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas('verified_payment_events', [
            'id' => $event->id,
            'currency' => 'THB',
        ]);
    }

    public function test_bot_cannot_be_deleted_while_it_has_a_verified_payment_event(): void
    {
        $event = app(PaymentProofService::class)
            ->record($this->bot, $this->conversation, $this->slip, $this->receipt, null);

        $this->assertAuthorityParentDeletionRejected('bots', $this->bot->id, $event);
    }

    public function test_conversation_cannot_be_deleted_while_it_has_a_verified_payment_event(): void
    {
        $event = app(PaymentProofService::class)
            ->record($this->bot, $this->conversation, $this->slip, $this->receipt, null);

        $this->assertAuthorityParentDeletionRejected(
            'conversations',
            $this->conversation->id,
            $event,
        );
    }

    public function test_slip_cannot_be_deleted_while_it_has_a_verified_payment_event(): void
    {
        $event = app(PaymentProofService::class)
            ->record($this->bot, $this->conversation, $this->slip, $this->receipt, null);

        $this->assertAuthorityParentDeletionRejected('slip_verifications', $this->slip->id, $event);
    }

    public function test_receipt_cannot_be_deleted_while_it_has_a_verified_payment_event(): void
    {
        $event = app(PaymentProofService::class)
            ->record($this->bot, $this->conversation, $this->slip, $this->receipt, null);

        $this->assertAuthorityParentDeletionRejected('messages', $this->receipt->id, $event);
    }

    public function test_order_deletion_nulls_only_the_nullable_order_relation_and_retains_the_event(): void
    {
        $event = app(PaymentProofService::class)
            ->record($this->bot, $this->conversation, $this->slip, $this->receipt, null);
        $order = Order::factory()->create([
            'bot_id' => $this->bot->id,
            'conversation_id' => $this->conversation->id,
            'message_id' => null,
        ]);
        DB::table('verified_payment_events')
            ->where('id', $event->id)
            ->update(['order_id' => $order->id]);

        $this->assertSame(1, DB::table('orders')->where('id', $order->id)->delete());

        $this->assertDatabaseHas('verified_payment_events', [
            'id' => $event->id,
            'order_id' => null,
        ]);
    }

    public function test_duplicate_event_key_race_returns_one_event_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql' || env('COMMERCE_SAFETY_PG_RACE') !== '1') {
            $this->markTestSkipped('Requires an explicitly opted-in disposable PostgreSQL test database.');
        }

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Requires pcntl_fork for the PostgreSQL race.');
        }

        $directory = sys_get_temp_dir().'/payment-proof-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];

        // RefreshDatabase wraps the test in a transaction. Commit these fixtures so
        // both independent child connections can observe them during the real race.
        DB::commit();
        DB::disconnect();

        for ($index = 0; $index < 2; $index++) {
            $pid = pcntl_fork();

            if ($pid === 0) {
                DB::purge();
                file_put_contents("{$directory}/ready-{$index}", 'ready');

                $deadline = microtime(true) + 10;
                while (! file_exists("{$directory}/go") && microtime(true) < $deadline) {
                    usleep(1000);
                }

                try {
                    $event = app(PaymentProofService::class)->record(
                        Bot::findOrFail($this->bot->id),
                        Conversation::findOrFail($this->conversation->id),
                        SlipVerification::findOrFail($this->slip->id),
                        Message::findOrFail($this->receipt->id),
                        null,
                    );
                    file_put_contents("{$directory}/result-{$index}", json_encode(['id' => $event->id]));
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents("{$directory}/result-{$index}", json_encode([
                        'error' => $exception::class,
                        'message' => $exception->getMessage(),
                    ]));
                    exit(1);
                }
            }

            $this->assertGreaterThan(0, $pid);
            $children[] = $pid;
        }

        $deadline = microtime(true) + 10;
        while ((! file_exists("{$directory}/ready-0") || ! file_exists("{$directory}/ready-1"))
            && microtime(true) < $deadline) {
            usleep(1000);
        }
        file_put_contents("{$directory}/go", 'go');

        $statuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }

        DB::purge();
        DB::reconnect();

        $results = [
            json_decode((string) file_get_contents("{$directory}/result-0"), true),
            json_decode((string) file_get_contents("{$directory}/result-1"), true),
        ];

        foreach (glob("{$directory}/*") as $file) {
            unlink($file);
        }
        rmdir($directory);

        $this->assertSame([0, 0], $statuses, json_encode($results));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, VerifiedPaymentEvent::count());
    }

    private function assertAuthorityParentDeletionRejected(
        string $table,
        int $parentId,
        VerifiedPaymentEvent $event,
    ): void {
        try {
            DB::transaction(function () use ($table, $parentId): void {
                DB::table($table)->where('id', $parentId)->delete();
                $this->fail("The [{$table}] authority parent was deleted.");
            });
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseHas($table, ['id' => $parentId]);
        $this->assertDatabaseHas('verified_payment_events', ['id' => $event->id]);
    }
}
