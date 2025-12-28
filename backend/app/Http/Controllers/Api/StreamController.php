<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Flow;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StreamController extends Controller
{
    private const THINKING_MODEL = 'deepseek/deepseek-r1-0528:free';
    private const NON_THINKING_MODEL = 'google/gemini-3-flash-preview';

    /**
     * Stream AI response with thinking process.
     *
     * This endpoint handles auth manually to support SSE streaming
     * without interference from middleware.
     */
    public function streamTest(Request $request, int $botId, int $flowId): StreamedResponse
    {
        // 1. Manual authentication (before streaming starts)
        $user = $this->authenticateFromToken($request);
        if (!$user) {
            return $this->errorResponse('Unauthorized', 401);
        }

        // 2. Validate input
        $message = $request->input('message');
        $conversationHistory = $request->input('conversation_history', []);
        $enableThinking = $request->boolean('enable_thinking', true);

        if (empty($message)) {
            return $this->errorResponse('Message is required', 400);
        }

        if (strlen($message) > 2000) {
            return $this->errorResponse('Message too long (max 2000 characters)', 400);
        }

        // 3. Load bot and flow (with authorization)
        $bot = Bot::find($botId);
        if (!$bot || $bot->user_id !== $user->id) {
            return $this->errorResponse('Bot not found', 404);
        }

        $flow = Flow::where('id', $flowId)->where('bot_id', $botId)->first();
        if (!$flow) {
            return $this->errorResponse('Flow not found', 404);
        }

        // 4. Get API key
        $apiKey = $bot->openrouter_api_key ?: config('services.openrouter.api_key');
        if (empty($apiKey)) {
            return $this->errorResponse('No API key configured. Please set up in Bot Connection settings.', 422);
        }

        // 5. Build messages
        $messages = $this->buildMessages($flow, $conversationHistory, $message);

        // 6. Determine model based on thinking mode
        $model = $enableThinking ? self::THINKING_MODEL : self::NON_THINKING_MODEL;

        // 7. Create SSE response
        return new StreamedResponse(function () use ($messages, $model, $apiKey, $enableThinking, $flow, $bot) {
            // Disable output buffering for streaming
            while (ob_get_level()) {
                ob_end_clean();
            }

            // Additional PHP settings for streaming
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', false);
            @ini_set('implicit_flush', true);

            try {
                $this->streamFromOpenRouter($messages, $model, $apiKey, $enableThinking);
            } catch (\Exception $e) {
                Log::error('Stream error', [
                    'bot_id' => $bot->id,
                    'flow_id' => $flow->id,
                    'error' => $e->getMessage(),
                ]);
                $this->sendSSE('error', ['message' => 'Streaming error: ' . $e->getMessage()]);
            }

            $this->sendSSE('done', ['status' => 'complete']);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no', // Disable nginx buffering
            'Access-Control-Allow-Origin' => config('app.frontend_url', '*'),
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Allow-Credentials' => 'true',
        ]);
    }

    /**
     * Authenticate user from Bearer token manually.
     */
    private function authenticateFromToken(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (!$token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        if (!$accessToken) {
            return null;
        }

        // Check if token is expired
        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return null;
        }

        return $accessToken->tokenable;
    }

    /**
     * Stream response from OpenRouter using Guzzle with true streaming.
     */
    private function streamFromOpenRouter(array $messages, string $model, string $apiKey, bool $enableThinking): void
    {
        $client = new Client([
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);

        $baseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');

        try {
            $response = $client->post($baseUrl . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => config('services.openrouter.site_url', config('app.url')),
                    'X-Title' => config('services.openrouter.site_name', config('app.name')),
                ],
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => true,
                    'temperature' => 0.7,
                    'max_tokens' => 4096,
                ],
                'stream' => true,
            ]);

            $body = $response->getBody();
            $buffer = '';
            $inThinking = false;

            while (!$body->eof()) {
                $chunk = $body->read(1024);
                if (empty($chunk)) {
                    continue;
                }

                $buffer .= $chunk;

                // Process complete SSE lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);

                    // Skip empty lines and comments
                    $line = trim($line);
                    if (empty($line) || strpos($line, ':') === 0) {
                        continue;
                    }

                    if (strpos($line, 'data: ') === 0) {
                        $data = substr($line, 6);

                        if ($data === '[DONE]') {
                            return;
                        }

                        $json = json_decode($data, true);
                        if ($json && isset($json['choices'][0]['delta']['content'])) {
                            $content = $json['choices'][0]['delta']['content'];

                            if ($enableThinking) {
                                // Parse thinking tags
                                $this->processThinkingContent($content, $inThinking);
                            } else {
                                $this->sendSSE('content', ['text' => $content]);
                            }
                        }

                        // Check for errors in stream
                        if ($json && isset($json['error'])) {
                            $errorMessage = $json['error']['message'] ?? 'Unknown API error';
                            $this->sendSSE('error', ['message' => $errorMessage]);
                            return;
                        }
                    }
                }

                // Check if client disconnected
                if (connection_aborted()) {
                    Log::info('Stream aborted by client');
                    return;
                }
            }
        } catch (GuzzleException $e) {
            Log::error('OpenRouter streaming error', ['error' => $e->getMessage()]);
            $this->sendSSE('error', ['message' => 'Failed to connect to AI service: ' . $e->getMessage()]);
        }
    }

    /**
     * Process content and extract thinking tags.
     * DeepSeek R1 uses <think>...</think> tags for reasoning.
     */
    private function processThinkingContent(string $content, bool &$inThinking): void
    {
        // Handle opening think tag
        if (strpos($content, '<think>') !== false) {
            $parts = explode('<think>', $content, 2);

            // Send any content before the tag
            if (!empty($parts[0])) {
                $this->sendSSE('content', ['text' => $parts[0]]);
            }

            $inThinking = true;
            $content = $parts[1] ?? '';

            if (empty($content)) {
                return;
            }
        }

        // Handle closing think tag
        if (strpos($content, '</think>') !== false) {
            $parts = explode('</think>', $content, 2);

            // Send thinking content before closing tag
            if (!empty($parts[0])) {
                $this->sendSSE('thinking', ['text' => $parts[0]]);
            }

            $inThinking = false;

            // Send content after closing tag
            if (isset($parts[1]) && !empty($parts[1])) {
                $this->sendSSE('content', ['text' => $parts[1]]);
            }
            return;
        }

        // Route to appropriate stream based on current state
        if ($inThinking) {
            $this->sendSSE('thinking', ['text' => $content]);
        } else {
            $this->sendSSE('content', ['text' => $content]);
        }
    }

    /**
     * Send SSE event to client.
     */
    private function sendSSE(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        // Check if client disconnected
        if (connection_aborted()) {
            exit;
        }

        // Force output
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /**
     * Build messages array for OpenRouter.
     */
    private function buildMessages(Flow $flow, array $history, string $message): array
    {
        $messages = [];

        // Add system prompt
        if ($flow->system_prompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $flow->system_prompt,
            ];
        }

        // Add conversation history
        foreach ($history as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            if (!empty($content) && in_array($role, ['user', 'assistant'])) {
                $messages[] = [
                    'role' => $role,
                    'content' => $content,
                ];
            }
        }

        // Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        return $messages;
    }

    /**
     * Return error as SSE stream.
     */
    private function errorResponse(string $message, int $status): StreamedResponse
    {
        return new StreamedResponse(function () use ($message) {
            $this->sendSSE('error', ['message' => $message]);
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Access-Control-Allow-Origin' => config('app.frontend_url', '*'),
        ]);
    }
}
