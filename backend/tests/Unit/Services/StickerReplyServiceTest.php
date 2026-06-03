<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\User;
use App\Services\ModelCapabilityService;
use App\Services\StickerReplyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StickerReplyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected array $stickerData = ['sticker_id' => '52002739', 'id' => 'msg-1'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouter.api_key' => 'test-api-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.default_model' => 'anthropic/claude-3.5-sonnet',
            'services.openrouter.fallback_model' => 'openai/gpt-4o-mini',
            'services.openrouter.site_url' => 'http://localhost',
            'services.openrouter.site_name' => 'TestApp',
            'services.openrouter.timeout' => 60,
            'services.openrouter.max_tokens' => 4096,
        ]);

        // Vision capability always supported in tests
        $this->mock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
        });
    }

    /**
     * Create a bot with AI sticker reply mode and a default flow prompt.
     *
     * @return array{0: Bot, 1: Conversation}
     */
    protected function makeBotWithAIStickerMode(string $flowPrompt): array
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'channel_type' => 'line',
            'primary_chat_model' => 'google/gemini-3.5-flash',
            'system_prompt' => null,
        ]);
        $flow = Flow::factory()->create([
            'bot_id' => $bot->id,
            'system_prompt' => $flowPrompt,
            'is_default' => true,
        ]);
        $bot->update(['default_flow_id' => $flow->id]);
        BotSetting::create([
            'bot_id' => $bot->id,
            'reply_sticker_enabled' => true,
            'reply_sticker_mode' => 'ai',
        ]);
        $conversation = Conversation::factory()->create(['bot_id' => $bot->id]);

        return [$bot->fresh(), $conversation];
    }

    protected function fakeVisionResponse(string $content): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'gen-1',
                'model' => 'google/gemini-3.5-flash',
                'choices' => [
                    [
                        'message' => ['content' => $content],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 8,
                    'total_tokens' => 18,
                ],
            ], 200),
        ]);
    }

    public function test_long_flow_prompt_is_truncated_in_sticker_system_prompt(): void
    {
        // Simulate a huge sales prompt: personality first, sales rules after 500 chars
        $salesPrompt = str_repeat('คุณคือกัปตันแอด พูดสุภาพ ', 30)
            ."\n\nกฎการขาย ห้ามเด็ดขาด: เริ่ม flow ขายใหม่ ตัวอย่าง \"ขอบคุณครับพี่ ส่งของให้ใน 5-10 นาทีนะครับ\"";
        $this->assertGreaterThan(500, mb_strlen($salesPrompt));

        [$bot, $conversation] = $this->makeBotWithAIStickerMode($salesPrompt);
        $this->fakeVisionResponse('ขอบคุณครับพี่ 🙏');

        app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        Http::assertSent(function ($request) {
            $system = collect($request['messages'])->firstWhere('role', 'system');

            // Sales rules beyond 500 chars must NOT leak into the sticker prompt
            return $system !== null
                && ! str_contains($system['content'], 'ห้ามเด็ดขาด')
                && ! str_contains($system['content'], '5-10 นาทีนะครับ');
        });
    }

    public function test_short_flow_prompt_is_kept_intact(): void
    {
        $shortPrompt = 'คุณคือกัปตันแอด ผู้ช่วยขายที่เป็นมิตร พูดสุภาพ ลงท้ายด้วยครับ';

        [$bot, $conversation] = $this->makeBotWithAIStickerMode($shortPrompt);
        $this->fakeVisionResponse('ขอบคุณครับพี่ 🙏');

        app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        Http::assertSent(function ($request) use ($shortPrompt) {
            $system = collect($request['messages'])->firstWhere('role', 'system');

            return $system !== null && str_contains($system['content'], $shortPrompt);
        });
    }

    public function test_garbage_output_starting_with_digit_falls_back_to_static_reply(): void
    {
        // Real production garbage from bot 26 (message id 70886)
        [$bot, $conversation] = $this->makeBotWithAIStickerMode('You are a friendly assistant.');
        $this->fakeVisionResponse('0 นาทีนะครับ" / "ขอบคุณ');

        $reply = app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        $this->assertEquals('ได้รับสติกเกอร์แล้วค่ะ', $reply);
    }

    public function test_garbage_output_with_unmatched_quotes_falls_back_to_static_reply(): void
    {
        [$bot, $conversation] = $this->makeBotWithAIStickerMode('You are a friendly assistant.');
        $this->fakeVisionResponse('ห้ามเด็ดขาด: เริ่ม flow ขายใหม่ "');

        $reply = app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        $this->assertEquals('ได้รับสติกเกอร์แล้วค่ะ', $reply);
    }

    public function test_too_short_output_falls_back_to_static_reply(): void
    {
        [$bot, $conversation] = $this->makeBotWithAIStickerMode('You are a friendly assistant.');
        $this->fakeVisionResponse('โอ');

        $reply = app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        $this->assertEquals('ได้รับสติกเกอร์แล้วค่ะ', $reply);
    }

    public function test_minimal_polite_reply_is_not_rejected(): void
    {
        // "ครับ" (4 chars) is a valid terse Thai acknowledgment, not garbage
        [$bot, $conversation] = $this->makeBotWithAIStickerMode('You are a friendly assistant.');
        $this->fakeVisionResponse('ครับ');

        $reply = app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        $this->assertEquals('ครับ', $reply);
    }

    public function test_static_fallback_uses_custom_message_when_configured(): void
    {
        [$bot, $conversation] = $this->makeBotWithAIStickerMode('You are a friendly assistant.');
        $bot->settings->update(['reply_sticker_message' => 'ขอบคุณสำหรับสติกเกอร์ครับ 🙏']);
        $bot->refresh();
        $this->fakeVisionResponse('0 นาทีนะครับ" / "ขอบคุณ');

        $reply = app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        $this->assertEquals('ขอบคุณสำหรับสติกเกอร์ครับ 🙏', $reply);
    }

    public function test_valid_ai_output_is_returned_unchanged(): void
    {
        [$bot, $conversation] = $this->makeBotWithAIStickerMode('You are a friendly assistant.');
        $this->fakeVisionResponse('ขอบคุณครับพี่ รอรับของได้เลยนะครับ 🙏');

        $reply = app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        $this->assertEquals('ขอบคุณครับพี่ รอรับของได้เลยนะครับ 🙏', $reply);
    }

    public function test_vision_call_uses_512_max_tokens(): void
    {
        [$bot, $conversation] = $this->makeBotWithAIStickerMode('You are a friendly assistant.');
        $this->fakeVisionResponse('ขอบคุณครับพี่ 🙏');

        app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        Http::assertSent(fn ($request) => $request['max_tokens'] === 512);
    }

    public function test_static_mode_returns_configured_message(): void
    {
        [$bot, $conversation] = $this->makeBotWithAIStickerMode('You are a friendly assistant.');
        $bot->settings->update([
            'reply_sticker_mode' => 'static',
            'reply_sticker_message' => 'ได้รับสติกเกอร์แล้วครับ',
        ]);
        $bot->refresh();

        $reply = app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        $this->assertEquals('ได้รับสติกเกอร์แล้วครับ', $reply);
        Http::assertNothingSent();
    }

    public function test_returns_null_when_sticker_reply_disabled(): void
    {
        [$bot, $conversation] = $this->makeBotWithAIStickerMode('You are a friendly assistant.');
        $bot->settings->update(['reply_sticker_enabled' => false]);
        $bot->refresh();

        $reply = app(StickerReplyService::class)->generateReply($bot, $conversation, $this->stickerData);

        $this->assertNull($reply);
    }
}
