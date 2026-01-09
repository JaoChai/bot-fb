<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramWebhook;
use App\Models\Bot;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramService $telegramService
    ) {}

    /**
     * Handle incoming Telegram webhook.
     */
    public function handle(Request $request, string $token): JsonResponse
    {
        // Find bot by webhook token
        $bot = $this->findBotByToken($token);

        if (!$bot) {
            Log::warning('Telegram webhook: Invalid token', ['token' => substr($token, 0, 8) . '...']);
            return response()->json(['ok' => false, 'error' => 'Invalid webhook token'], 404);
        }

        // Optional: Validate secret token header (if set during webhook setup)
        $secretToken = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if ($bot->channel_secret && $secretToken !== $bot->channel_secret) {
            Log::warning('Telegram webhook: Invalid secret token', ['bot_id' => $bot->id]);
            return response()->json(['ok' => false, 'error' => 'Invalid secret token'], 401);
        }

        // Parse update from webhook body
        $update = $request->json()->all();

        if (empty($update)) {
            Log::debug('Telegram webhook: Empty update', ['bot_id' => $bot->id]);
            return response()->json(['ok' => true]);
        }

        // Log webhook received
        Log::info('Telegram webhook received', [
            'bot_id' => $bot->id,
            'update_id' => $update['update_id'] ?? null,
            'type' => $this->getUpdateType($update),
        ]);

        // Dispatch job for processing (async)
        ProcessTelegramWebhook::dispatch($bot, $update)
            ->onQueue('webhooks');

        // Return 200 OK immediately - Telegram requires fast response
        return response()->json(['ok' => true]);
    }

    /**
     * Find bot by webhook token.
     */
    protected function findBotByToken(string $token): ?Bot
    {
        // Build the expected webhook URL (using /api/webhook/ path for proxy compatibility)
        $webhookUrl = config('app.url') . '/api/webhook/telegram/' . $token;

        return Bot::where('webhook_url', $webhookUrl)
            ->where('channel_type', 'telegram')
            ->first();
    }

    /**
     * Get the type of update for logging.
     */
    protected function getUpdateType(array $update): string
    {
        return match (true) {
            isset($update['message']) => 'message',
            isset($update['edited_message']) => 'edited_message',
            isset($update['channel_post']) => 'channel_post',
            isset($update['callback_query']) => 'callback_query',
            isset($update['inline_query']) => 'inline_query',
            isset($update['my_chat_member']) => 'my_chat_member',
            isset($update['chat_member']) => 'chat_member',
            default => 'unknown',
        };
    }
}
