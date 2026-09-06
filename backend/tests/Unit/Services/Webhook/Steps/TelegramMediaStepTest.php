<?php

namespace Tests\Unit\Services\Webhook\Steps;

use App\Models\Bot;
use App\Services\TelegramService;
use App\Services\Webhook\Steps\Telegram\TelegramMediaStep;
use App\Services\Webhook\WebhookContext;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class TelegramMediaStepTest extends TestCase
{
    private function ctx(string $messageType, array $metadata = []): WebhookContext
    {
        $bot = new Bot(['id' => 1]);
        $ctx = new WebhookContext($bot, ['type' => 'message', 'message' => ['type' => $messageType]], 'telegram');
        $ctx->metadata = $metadata;

        return $ctx;
    }

    public function test_text_message_sets_empty_media_and_null_placeholder(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldNotReceive('downloadAndStoreFile');
        $ctx = $this->ctx('text');
        $called = false;

        (new TelegramMediaStep($telegram))->handle($ctx, function () use (&$called) {
            $called = true;
        });

        $this->assertSame([], $ctx->metadata['media']);
        $this->assertNull($ctx->metadata['placeholder']);
        $this->assertTrue($called);
    }

    public function test_photo_download_sets_url_mime_and_merged_metadata(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('downloadAndStoreFile')
            ->once()
            ->with(Mockery::type(Bot::class), 'F1')
            ->andReturn([
                'url' => 'https://x/p.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 123,
                'path' => 'tg/p.jpg',
            ]);
        $ctx = $this->ctx('photo', ['file_id' => 'F1', 'media_metadata' => ['width' => 10]]);

        (new TelegramMediaStep($telegram))->handle($ctx, fn () => null);

        $this->assertSame('https://x/p.jpg', $ctx->metadata['media']['url']);
        $this->assertSame('image/jpeg', $ctx->metadata['media']['mime_type']);
        $this->assertSame([
            'width' => 10,
            'file_id' => 'F1',
            'file_size' => 123,
            'storage_path' => 'tg/p.jpg',
        ], $ctx->metadata['media']['metadata']);
        $this->assertSame('[Photo]', $ctx->metadata['placeholder']);
    }

    public function test_failed_download_flags_download_failed_and_keeps_placeholder(): void
    {
        Log::shouldReceive('warning')->once();
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldReceive('downloadAndStoreFile')
            ->once()
            ->with(Mockery::type(Bot::class), 'F2')
            ->andReturn(null);
        $ctx = $this->ctx('voice', ['file_id' => 'F2']);

        (new TelegramMediaStep($telegram))->handle($ctx, fn () => null);

        $this->assertArrayNotHasKey('url', $ctx->metadata['media']);
        $this->assertSame([
            'file_id' => 'F2',
            'download_failed' => true,
        ], $ctx->metadata['media']['metadata']);
        $this->assertSame('[Voice message]', $ctx->metadata['placeholder']);
    }

    public function test_location_without_file_id_uses_metadata_only(): void
    {
        $telegram = Mockery::mock(TelegramService::class);
        $telegram->shouldNotReceive('downloadAndStoreFile');
        $ctx = $this->ctx('location', ['media_metadata' => ['title' => 'Office']]);

        (new TelegramMediaStep($telegram))->handle($ctx, fn () => null);

        $this->assertSame(['metadata' => ['title' => 'Office']], $ctx->metadata['media']);
        $this->assertSame('[Location: Office]', $ctx->metadata['placeholder']);
    }
}
