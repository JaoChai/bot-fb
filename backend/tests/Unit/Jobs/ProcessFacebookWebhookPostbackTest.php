<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessFacebookWebhook;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Characterization test for the postback path of ProcessFacebookWebhook.
 *
 * Pins the CURRENT job's handlePostback behavior (pre-mapper wiring) against
 * the tests/fixtures/facebook-postback.php body — the same fixture the mapper
 * unit tests consume.
 *
 * Known bug pinned here (do not fix in this refactor): handlePostback()
 * writes messages.type='postback' but the messages.type enum from
 * database/migrations/2025_12_23_145426_create_messages_table.php has no
 * 'postback' value, so the user-message insert fails on the CHECK
 * constraint. Tracked for a separate schema-fix task.
 */
class ProcessFacebookWebhookPostbackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Bot $bot;

    protected array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->active()->facebook()->create([
            'user_id' => $this->user->id,
            'channel_access_token' => 'fb_test_token',
        ]);

        $this->payload = include base_path('tests/fixtures/facebook-postback.php');
    }

    public function test_postback_message_save_hits_schema_constraint_known_bug(): void
    {
        // Pins pre-existing bug: 'postback' is not a valid value in the
        // messages.type enum (database/migrations/2025_12_23_145426_create_messages_table.php).
        // Do not fix here — tracked for a separate schema-fix task.
        Http::fake([
            'graph.facebook.com/*' => Http::response(['ok' => true], 200),
        ]);
        Queue::fake();

        $aiService = Mockery::mock(AIService::class);

        $job = new ProcessFacebookWebhook($this->bot, $this->payload);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $job->handle($aiService);
    }

    public function test_postback_message_save_hits_schema_constraint_known_bug_inactive_bot(): void
    {
        // Pins pre-existing bug (inactive bot variant): the postback user-message
        // insert fails on the messages.type CHECK constraint before the AI
        // response path is reached.
        Http::fake([
            'graph.facebook.com/*' => Http::response(['ok' => true], 200),
        ]);
        Queue::fake();

        $this->bot->update(['status' => 'paused']);

        $aiService = Mockery::mock(AIService::class);
        $aiService->shouldNotReceive('generateAndSaveResponse');

        $job = new ProcessFacebookWebhook($this->bot, $this->payload);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $job->handle($aiService);
    }
}
