<?php

namespace Tests\Feature\Chat;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AIService;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\MessageAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * เทสต์เดินเส้นทางงานจริงของบอท 26 (ProcessAggregatedMessages) ด้วย AIService +
 * RAGService ตัวจริง และ fake OpenRouter ทาง HTTP — กันถอยหลังสองจุดที่ QA เจอ:
 * (1) การเรียกตัวตัดหางต้องมีผลกับทั้งแถวที่บันทึกและข้อความที่ส่งจริง
 * (2) metadata.llm (generation_id, finish_reason) ต้องถูกเก็บที่จุดบันทึกของ job
 * (QA mutation: ถอด generation_id ที่จุดบันทึกของ job แล้วเทสต์ชุดเดิมยังผ่าน 22/22)
 *
 * สตริงทั้งหมดสังเคราะห์ ไม่มีข้อความลูกค้าจริง
 */
class LeakTailGuardRealPathTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $overrides */
    private function fakeOpenRouter(string $content, string $finish = 'stop', ?string $id = 'gen-real-1', array $overrides = []): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key']);
        Http::fake(['*' => Http::response(array_merge([
            'id' => $id,
            'model' => 'openai/gpt-6-luna',
            'choices' => [['finish_reason' => $finish, 'message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], $overrides))]);
    }

    #[Test]
    public function job_path_saves_and_delivers_the_stripped_reply_with_llm_metadata(): void
    {
        Cache::flush();
        $user = User::factory()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'total_messages' => 0,
            'context_window' => 10,
            'primary_chat_model' => 'openai/gpt-6-luna',
        ]);
        $conversation = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_synthetic',
            'message_count' => 1,
            'unread_count' => 0,
            'is_handover' => false,
        ]);
        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'ครับ',
        ]);

        $aggregation = app(MessageAggregationService::class);
        $group = $aggregation->startOrContinueAggregation($conversation, $userMessage, 0);

        // โมเดล (fake) ตอบมีหางหลุดท้ายกล่อง — บรรทัดเดียว
        $this->fakeOpenRouter('รับทราบครับพี่ เครดิตฟรี', 'stop', 'gen-JOB-9');

        $delivered = null;
        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once()->andReturnUsing(function ($b, $c, $m) use (&$delivered) {
            $delivered = $m->content;
        });

        (new ProcessAggregatedMessages($bot, $conversation, $group['group_id'], 'U_synthetic'))
            ->handle($aggregation, app(AIService::class), $delivery);

        $botMessage = Message::where('conversation_id', $conversation->id)
            ->where('sender', 'bot')
            ->sole();

        // ที่บันทึกและที่ส่งจริงต้องเป็นคำตอบที่ถูกตัดหาง
        $this->assertSame('รับทราบครับพี่', $botMessage->content);
        $this->assertSame('รับทราบครับพี่', $delivered);

        // หลักฐานการเรียกโมเดล + ธงตัวตัด ต้องอยู่ที่จุดบันทึกของ job
        $this->assertSame('gen-JOB-9', $botMessage->metadata['llm']['generation_id']);
        $this->assertSame('stop', $botMessage->metadata['llm']['finish_reason']);
        $this->assertSame('stripped', $botMessage->metadata['leak_guard']);
    }

    #[Test]
    public function save_path_with_real_services_strips_per_bubble_and_records_metadata(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'context_window' => 10,
            'primary_chat_model' => 'openai/gpt-6-luna',
        ]);
        $conversation = Conversation::factory()->line()->create(['bot_id' => $bot->id]);

        // สองกล่อง: กล่องแรกปกติ, กล่องสองมีหางหลุด (finish_reason=length เพื่อยืนยันเก็บตามจริง)
        $this->fakeOpenRouter('รับทราบครับพี่|||ขอบคุณครับ เครดิตฟรี? no. ask more.', 'length', 'gen-SAVE-2');
        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'ครับ',
        ]);

        $botMessage = app(AIService::class)->generateAndSaveResponse($bot, $conversation, $userMessage);

        $this->assertSame('รับทราบครับพี่|||ขอบคุณครับ', $botMessage->content);
        $this->assertSame('gen-SAVE-2', $botMessage->metadata['llm']['generation_id']);
        $this->assertSame('length', $botMessage->metadata['llm']['finish_reason']);
        $this->assertSame('stripped', $botMessage->metadata['leak_guard']);
    }
}
