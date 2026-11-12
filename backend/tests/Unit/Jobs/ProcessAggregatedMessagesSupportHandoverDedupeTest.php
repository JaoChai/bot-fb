<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAggregationGroup;
use App\Services\AIService;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\MessageAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * End-to-end through the aggregation job: a Support Router handover that
 * duplicates a recent Support pointer is saved with EMPTY content — the job
 * delivers NOTHING to the customer, still hands the group over (nothing left
 * pending), and the row carries support_router.duplicate_suppressed.
 */
class ProcessAggregatedMessagesSupportHandoverDedupeTest extends TestCase
{
    use RefreshDatabase;

    private const DECISIONS_URL = 'https://openrouter.ai/api/alpha/decisions';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'cache.default' => 'array',
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.decisions_url' => self::DECISIONS_URL,
            'services.openrouter.support_router_threshold' => 0.7,
            'delivery.order_payload_enabled' => false,
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_suppressed_duplicate_handover_delivers_nothing_and_hands_group_over(): void
    {
        $bot = Bot::factory()->active()->line()->create([
            'total_messages' => 0,
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'กรุณาติดต่อทีมซัพพอร์ตที่ https://support.example.com/th ครับ',
        ]);
        $conversation = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_test',
            'message_count' => 1,
            'unread_count' => 0,
            'is_handover' => false,
        ]);

        // The bot pointed the customer to Support 10 s ago (vision reply with the
        // handover URL) — strictly BEFORE the user message that triggers this turn,
        // so the hasAlreadyResponded safety net does not fire.
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ',
            'model_used' => 'vision-model',
            'created_at' => now()->subSeconds(10),
        ]);

        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'ยังเข้าไม่ได้ครับ',
        ]);

        $aggregation = app(MessageAggregationService::class);
        $group = $aggregation->startOrContinueAggregation($conversation, $userMessage, 0);
        $this->assertNotNull($group);
        $groupId = $group['group_id'];

        Http::fake([
            self::DECISIONS_URL => function ($request) {
                if (isset($request->data()['questions']['buying'])) {
                    return Http::response([
                        'model' => 'openai/gpt-6-luna-decisions-20261006',
                        'answers' => ['buying' => ['type' => 'noul', 'noul' => 0.25]],
                        'usage' => ['input_tokens' => 31, 'output_tokens' => 0, 'cost' => 0.0000021],
                        'id' => 'gen-dec-buy',
                        'provider' => 'OpenAI',
                    ]);
                }

                return Http::response([
                    'model' => 'openai/gpt-6-luna-decisions-20261006',
                    'answers' => ['support' => ['type' => 'noul', 'noul' => 0.9]],
                    'usage' => ['input_tokens' => 639, 'output_tokens' => 0, 'cost' => 0.0000639],
                    'id' => 'gen-dec-123',
                    'provider' => 'OpenAI',
                ]);
            },
        ]);

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->never();

        (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
            ->handle($aggregation, app(AIService::class), $delivery);

        $botMessage = Message::where('conversation_id', $conversation->id)
            ->where('sender', 'bot')
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame('', $botMessage->content);
        $this->assertSame('support_router', $botMessage->model_used);
        $this->assertTrue($botMessage->metadata['support_router']['duplicate_suppressed']);
        $this->assertTrue($botMessage->metadata['support_router']['handover']);

        // the turn is answered: the group row is handed over (deleted), not left pending
        $this->assertFalse($aggregation->isActiveGroup($conversation->id, $groupId));
        $this->assertTrue(MessageAggregationGroup::where('conversation_id', $conversation->id)->doesntExist());
    }

    public function test_suppressed_duplicate_handover_records_router_handover_timing_outcome(): void
    {
        // Lead widening item 3: the suppressed turn writes the SAME timing
        // outcome as the webhook path (router_handover, decided from
        // model_used = support_router), not the default 'failed'. Delivery
        // stays skipped and the group is still handed over.
        $bot = Bot::factory()->active()->line()->create([
            'total_messages' => 0,
            'support_router_mode' => 'on',
            'support_router_model' => 'openai/gpt-6-luna-decisions',
            'support_handover_message' => 'กรุณาติดต่อทีมซัพพอร์ตที่ https://support.example.com/th ครับ',
        ]);
        $conversation = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_test',
            'message_count' => 1,
            'unread_count' => 0,
            'is_handover' => false,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender' => 'bot',
            'content' => 'กรุณาติดต่อ https://support.example.com/th ครับ',
            'model_used' => 'vision-model',
            'created_at' => now()->subSeconds(10),
        ]);

        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'ยังเข้าไม่ได้ครับ',
        ]);

        $aggregation = app(MessageAggregationService::class);
        $group = $aggregation->startOrContinueAggregation($conversation, $userMessage, 0);
        $this->assertNotNull($group);
        $groupId = $group['group_id'];

        Http::fake([
            self::DECISIONS_URL => function ($request) {
                if (isset($request->data()['questions']['buying'])) {
                    return Http::response([
                        'model' => 'openai/gpt-6-luna-decisions-20261006',
                        'answers' => ['buying' => ['type' => 'noul', 'noul' => 0.25]],
                        'usage' => ['input_tokens' => 31, 'output_tokens' => 0, 'cost' => 0.0000021],
                        'id' => 'gen-dec-buy',
                        'provider' => 'OpenAI',
                    ]);
                }

                return Http::response([
                    'model' => 'openai/gpt-6-luna-decisions-20261006',
                    'answers' => ['support' => ['type' => 'noul', 'noul' => 0.9]],
                    'usage' => ['input_tokens' => 639, 'output_tokens' => 0, 'cost' => 0.0000639],
                    'id' => 'gen-dec-123',
                    'provider' => 'OpenAI',
                ]);
            },
        ]);

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->never();

        (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
            ->handle($aggregation, app(AIService::class), $delivery);

        $timingRow = DB::table('reply_phase_timings')
            ->where('conversation_id', $conversation->id)
            ->first();

        $this->assertNotNull($timingRow, 'the attempted turn must write a timing row');
        $this->assertSame('router_handover', $timingRow->outcome);
        $this->assertSame('support_router', $timingRow->model);
        $this->assertFalse($aggregation->isActiveGroup($conversation->id, $groupId));
    }
}
