<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AIService;
use App\Services\RAGService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIServiceHistoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ข้อความ turn ปัจจุบันถูก save ลง DB ก่อน generateResponse เสมอ (pipeline Stage 2)
     * ถ้าไม่ exclude ออกจาก history LLM จะเห็นข้อความเดิมซ้ำ 2 turn ติดกัน
     * (history แถวสุดท้าย + append เป็น current message) → ตีความจำนวนผิด เช่น สั่ง 1 เป็น 2
     */
    public function test_generate_response_excludes_current_turn_messages_from_history(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $old = $conversation->messages()->create([
            'sender' => 'user', 'content' => 'สวัสดีครับ', 'type' => 'text',
        ]);
        $current = $conversation->messages()->create([
            'sender' => 'user', 'content' => 'เอา BM x1 = 1,100 บาท', 'type' => 'text',
        ]);

        $captured = null;
        $this->mock(RAGService::class, function ($m) use (&$captured) {
            $m->shouldReceive('generateResponse')->once()
                ->andReturnUsing(function ($bot, $userMessage, $conversationHistory = []) use (&$captured) {
                    $captured = $conversationHistory;

                    return ['content' => 'ok', 'model' => 'test', 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0]];
                });
        });

        app(AIService::class)->generateResponse(
            $bot, $current->content, $conversation, excludeMessageIds: [$current->id],
        );

        $this->assertNotNull($captured);
        $contents = array_column($captured, 'content');
        $this->assertContains('สวัสดีครับ', $contents);
        $this->assertNotContains('เอา BM x1 = 1,100 บาท', $contents,
            'current-turn message must not appear in history (it is appended separately)');
    }

    /**
     * ไม่ส่ง excludeMessageIds → พฤติกรรมเดิมทุกอย่าง (backward compatible)
     */
    public function test_generate_response_without_exclusion_keeps_full_history(): void
    {
        [$bot, $conversation] = $this->makeBotWithConversation();

        $conversation->messages()->create([
            'sender' => 'user', 'content' => 'ข้อความเก่า', 'type' => 'text',
        ]);

        $captured = null;
        $this->mock(RAGService::class, function ($m) use (&$captured) {
            $m->shouldReceive('generateResponse')->once()
                ->andReturnUsing(function ($bot, $userMessage, $conversationHistory = []) use (&$captured) {
                    $captured = $conversationHistory;

                    return ['content' => 'ok', 'model' => 'test', 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0]];
                });
        });

        app(AIService::class)->generateResponse($bot, 'คำถามใหม่', $conversation);

        $this->assertSame(['ข้อความเก่า'], array_column($captured, 'content'));
    }

    /** @return array{0: Bot, 1: Conversation} */
    private function makeBotWithConversation(): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id, 'context_window' => 10]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot, $conversation];
    }
}
