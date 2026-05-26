<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\StreamController;
use App\Models\Bot;
use App\Models\Flow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Feature test for StreamController::streamTest (POST /api/bots/{botId}/flows/{flowId}/stream).
 *
 * Acts as a regression guard before the Sprint 5 Task C extraction of StreamController.
 * Locks the controller's public contract: auth/authz/validation status codes and the
 * boundary SSE events (`process_start` ... `done`) in the happy-path stream body.
 *
 * Drift discoveries (see commit message):
 * - D16: actual SSE event names are process_start / decision_* / kb_* / chat_* /
 *   content / done / error — NOT the plan's init/decision/knowledge_base/chunk/done.
 * - Route URL segment is `/stream` (not `/stream-test`); controller method is streamTest.
 */
class StreamControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build the stream endpoint URL for the given bot + flow IDs.
     */
    private function streamUrl(int $botId, int $flowId): string
    {
        return "/api/bots/{$botId}/flows/{$flowId}/stream";
    }

    /**
     * Bind a Mockery partial mock of StreamController into the container that
     * intercepts the protected `sendSSE` method. The mock records events into
     * the supplied array (by reference) and short-circuits the echo/flush calls.
     *
     * This is necessary because StreamController's stream callback does:
     *   while (ob_get_level()) { ob_end_clean(); }
     * which closes any test-side ob_start buffer (including Laravel's own
     * TestResponse::streamedContent() buffer) BEFORE any data is echoed.
     * Capturing the stdout side-channel without modifying the controller is
     * impractical, so we intercept at the sendSSE boundary instead. The body
     * we'd reconstruct is `event: <name>\ndata: <json>\n\n` per event — we
     * format it the same way so assertions match the on-the-wire SSE format.
     */
    private function bindSseRecorder(string &$body): void
    {
        $mock = Mockery::mock(StreamController::class, [
            $this->app->make(\App\Services\OpenRouterService::class),
            $this->app->make(\App\Services\HybridSearchService::class),
            $this->app->make(\App\Services\IntentAnalysisService::class),
            $this->app->make(\App\Services\RAGService::class),
            $this->app->make(\App\Services\MultipleBubblesService::class),
            $this->app->make(\App\Services\SemanticCacheService::class),
        ])->makePartial();
        $mock->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('sendSSE')
            ->andReturnUsing(function (string $event, array $data) use (&$body, $mock) {
                $body .= "event: {$event}\n";
                $body .= 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";
                if ($event === 'done') {
                    // Mirror the controller's doneSent guard so duplicate-suppression works.
                    $r = new \ReflectionProperty($mock, 'doneSent');
                    $r->setValue($mock, true);
                }

                return true;
            });

        $this->app->instance(StreamController::class, $mock);
    }

    /**
     * Configure the OpenRouter integration so the controller can reach the API-key check
     * but the actual Guzzle streaming call fails fast (127.0.0.1:1 refuses immediately).
     *
     * Stubbing strategy: hybrid of brief Option (b) + Mockery partial mock on the
     * controller's sendSSE method (necessary because the controller's stream callback
     * starts with `while (ob_get_level()) ob_end_clean();` which destroys any output
     * buffer we'd install to capture stdout). We let the inline Guzzle call fail
     * naturally — 127.0.0.1:1 refuses with connect_timeout=1s, so the chat-model
     * branch throws, the outer catch emits `error`, and the finally block emits
     * `done`. process_start, decision_skip, kb_skip, chat_start, chat_fallback,
     * error, done — all captured via the sendSSE intercept. That's the controller's
     * actual contract.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openrouter.api_key' => 'sk-test-key',
            'services.openrouter.base_url' => 'http://127.0.0.1:1',
            'services.openrouter.connect_timeout' => 1,
            'services.openrouter.stream_timeout' => 2,
        ]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $bot = Bot::factory()->create();
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);

        $response = $this->postJson($this->streamUrl($bot->id, $flow->id), [
            'message' => 'hello',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_cannot_stream_for_another_users_bot(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $bot = Bot::factory()->create(['user_id' => $owner->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);

        $token = $intruder->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson($this->streamUrl($bot->id, $flow->id), [
                'message' => 'hello',
            ]);

        $response->assertStatus(404);
    }

    public function test_missing_message_returns_400(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson($this->streamUrl($bot->id, $flow->id), [
                'message' => '',
            ]);

        $response->assertStatus(400);
    }

    public function test_message_over_2000_chars_returns_400(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson($this->streamUrl($bot->id, $flow->id), [
                'message' => str_repeat('a', 2001),
            ]);

        $response->assertStatus(400);
    }

    public function test_non_existent_bot_returns_404(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson($this->streamUrl(999_999, 1), [
                'message' => 'hello',
            ]);

        $response->assertStatus(404);
    }

    public function test_non_existent_flow_returns_404(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson($this->streamUrl($bot->id, 999_999), [
                'message' => 'hello',
            ]);

        $response->assertStatus(404);
    }

    public function test_missing_api_key_returns_422(): void
    {
        // Override the setUp default to simulate "no API key configured"
        config(['services.openrouter.api_key' => null]);

        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson($this->streamUrl($bot->id, $flow->id), [
                'message' => 'hello',
            ]);

        $response->assertStatus(422);
    }

    public function test_happy_path_emits_process_start_and_done_events(): void
    {
        $user = User::factory()->create();
        // No decision_model -> takes decision_skip branch (deterministic, no HTTP)
        // No kb_enabled    -> takes kb_skip branch        (deterministic, no HTTP)
        // No fallback_chat_model -> chat model attempt fails (127.0.0.1:1), throws.
        // Outer catch emits `error`, finally emits `done`. process_start was already
        // emitted at the top of the stream callback. That's the contract we lock.
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'decision_model' => null,
            'fallback_decision_model' => null,
            'primary_chat_model' => 'anthropic/claude-3.5-sonnet',
            'fallback_chat_model' => null,
            'kb_enabled' => false,
        ]);
        $flow = Flow::factory()->create(['bot_id' => $bot->id]);

        $token = $user->createToken('test')->plainTextToken;

        // Bind a partial mock that intercepts sendSSE so we can capture the
        // event stream without fighting the controller's ob_end_clean() loop.
        $body = '';
        $this->bindSseRecorder($body);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson($this->streamUrl($bot->id, $flow->id), [
                'message' => 'hello',
            ]);

        $response->assertStatus(200);

        // sendContent() runs the stream callback which invokes our mocked sendSSE.
        // The controller's first action is `while (ob_get_level()) ob_end_clean();`
        // — we install a sacrificial buffer it can consume, then re-open one to
        // satisfy PHPUnit's "buffers not closed by test code" risk check.
        $initialLevel = ob_get_level();
        ob_start(); // sacrificial buffer the controller will close
        try {
            $response->baseResponse->sendContent();
        } finally {
            // Restore the original buffering stack so PHPUnit's output capturing works.
            while (ob_get_level() < $initialLevel) {
                ob_start();
            }
        }

        // Boundary events must both appear
        $this->assertStringContainsString('event: process_start', $body, 'process_start event missing');
        $this->assertStringContainsString('event: done', $body, 'done event missing');

        // process_start must appear before done
        $startPos = strpos($body, 'event: process_start');
        $donePos = strpos($body, 'event: done');
        $this->assertNotFalse($startPos);
        $this->assertNotFalse($donePos);
        $this->assertLessThan($donePos, $startPos, 'process_start must appear before done');

        // At least one of the pipeline-stage events must appear between boundaries
        $between = substr($body, $startPos, $donePos - $startPos);
        $this->assertMatchesRegularExpression(
            '/event: (decision_skip|decision_start|decision_result|decision_fallback|kb_skip|kb_search|kb_result|chat_start|chat_fallback|content|error)/',
            $between,
            'expected at least one pipeline-stage event between process_start and done'
        );

        // `done` should be emitted exactly once (controller guards against double-send via $doneSent)
        $doneCount = substr_count($body, 'event: done');
        $this->assertSame(1, $doneCount, 'done event should be emitted exactly once');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
