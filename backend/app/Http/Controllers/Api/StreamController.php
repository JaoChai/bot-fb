<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\User;
use App\Services\Streaming\StreamingResponseOrchestrator;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * StreamController - System Process Logging for Chat Emulator
 *
 * Thin HTTP/SSE adapter for the chat-emulator stream endpoint. Handles auth,
 * input validation, bot/flow lookup, and the StreamedResponse lifecycle, then
 * delegates the actual streaming pipeline (decision → KB → chat → done) to
 * StreamingResponseOrchestrator.
 *
 * SSE event order, timing, and shape are byte-identical to the previous
 * monolithic controller — frontend `useStreamingChat` depends on this.
 */
class StreamController extends Controller
{
    public function __construct(
        private StreamingResponseOrchestrator $orchestrator,
    ) {}

    /**
     * Stream AI response with System Process Logging.
     * Shows each step: Decision Model, KB Search, Chat Model
     */
    public function streamTest(Request $request, int $botId, int $flowId): StreamedResponse
    {
        // 1. Manual authentication (before streaming starts)
        $user = $this->authenticateFromToken($request);
        if (! $user) {
            return $this->errorResponse('Unauthorized', 401);
        }

        // 2. Validate input
        $message = $request->input('message');
        $conversationHistory = $request->input('conversation_history', []);
        $conversationId = $request->input('conversation_id');
        // Limit conversation history to prevent excessive token usage
        $maxHistory = (int) config('rag.max_conversation_history', 20);
        $conversationHistory = array_slice($conversationHistory, -$maxHistory);
        $conversationHistory = $this->truncateHistoryByTokens(
            $conversationHistory,
            (int) config('rag.max_history_tokens', 4000)
        );

        if (empty($message)) {
            return $this->errorResponse('Message is required', 400);
        }

        if (strlen($message) > 2000) {
            return $this->errorResponse('Message too long (max 2000 characters)', 400);
        }

        // 3. Load bot and flow (with authorization)
        $bot = Bot::find($botId);
        if (! $bot || $bot->user_id !== $user->id) {
            return $this->errorResponse('Bot not found', 404);
        }

        $flow = Flow::where('id', $flowId)->where('bot_id', $botId)->first();
        if (! $flow) {
            return $this->errorResponse('Flow not found', 404);
        }

        // 4. Get API key: User Settings > ENV
        $apiKey = $bot->user?->settings?->getOpenRouterApiKey() ?? config('services.openrouter.api_key');
        if (empty($apiKey)) {
            return $this->errorResponse('No API key configured. Please set up in Settings page.', 422);
        }

        // 5. Load memory notes from conversation (if provided)
        $memoryNotes = [];
        if ($conversationId) {
            $conversation = Conversation::where('id', $conversationId)
                ->where('bot_id', $botId)
                ->first();
            if ($conversation) {
                $memoryNotes = collect($conversation->memory_notes ?? [])
                    ->where('type', 'memory')
                    ->pluck('content')
                    ->all();
            }
        }

        // 6. Create SSE response, delegating the pipeline to the orchestrator
        $orchestrator = $this->orchestrator;

        return new StreamedResponse(function () use ($orchestrator, $bot, $flow, $message, $conversationHistory, $apiKey, $memoryNotes) {
            // Disable output buffering for streaming
            while (ob_get_level()) {
                ob_end_clean();
            }

            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);
            @ini_set('implicit_flush', true);

            // Eager load knowledgeBases to prevent lazy loading in the KB search stage.
            $flow->loadMissing('knowledgeBases');

            $orchestrator->run(
                bot: $bot,
                flow: $flow,
                message: $message,
                conversationHistory: $conversationHistory,
                apiKey: $apiKey,
                memoryNotes: $memoryNotes,
                onSseEvent: fn (string $event, array $data) => $this->sendSSE($event, $data),
            );
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
            'Access-Control-Allow-Origin' => config('app.frontend_url', '*'),
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Allow-Credentials' => 'true',
        ]);
    }

    // =====================
    // Helper Methods
    // =====================

    protected function authenticateFromToken(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (! $accessToken) {
            return null;
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return null;
        }

        return $accessToken->tokenable;
    }

    /**
     * Send SSE event to client.
     *
     * @param  string  $event  Event name
     * @param  array  $data  Event data
     * @return bool True if event was sent successfully, false if connection was aborted
     */
    protected function sendSSE(string $event, array $data): bool
    {
        // Check connection before sending (don't exit, just return false)
        if (connection_aborted()) {
            return false;
        }

        echo "event: {$event}\n";
        echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        return true;
    }

    protected function errorResponse(string $message, int $status): StreamedResponse
    {
        return new StreamedResponse(function () use ($message) {
            $this->sendSSE('error', ['message' => $message]);
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => config('app.frontend_url', '*'),
        ]);
    }

    /**
     * Truncate conversation history to fit within token budget.
     * Keeps most recent messages, drops oldest first.
     */
    private function truncateHistoryByTokens(array $history, int $maxTokens): array
    {
        if (empty($history) || $maxTokens <= 0) {
            return $history;
        }

        $totalTokens = 0;
        $result = [];

        // Walk backwards (most recent first)
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $content = $history[$i]['content'] ?? '';
            // Approximate: 1 token ≈ 4 chars for Thai/English mix
            $estimatedTokens = (int) ceil(mb_strlen($content) / 4);

            if ($totalTokens + $estimatedTokens > $maxTokens && ! empty($result)) {
                break;
            }

            $totalTokens += $estimatedTokens;
            array_unshift($result, $history[$i]);
        }

        return $result;
    }
}
