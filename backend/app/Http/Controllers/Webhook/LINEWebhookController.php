<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Services\LINEService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LINEWebhookController extends Controller
{
    public function __construct(
        protected LINEService $lineService
    ) {}

    /**
     * Handle incoming LINE webhook.
     */
    public function handle(Request $request, string $token): JsonResponse
    {
        // Find bot by webhook token (only LINE bots)
        $bot = $this->findBotByToken($token);

        if (!$bot) {
            Log::warning('LINE webhook: Invalid token', ['token' => substr($token, 0, 8) . '...']);
            return response()->json(['message' => 'Invalid webhook token'], 404);
        }

        // Validate LINE signature
        $signature = $request->header('X-Line-Signature');
        if (!$signature) {
            Log::warning('LINE webhook: Missing signature', ['bot_id' => $bot->id]);
            return response()->json(['message' => 'Missing X-Line-Signature header'], 401);
        }

        // Check if channel_secret is configured
        if (empty($bot->channel_secret)) {
            Log::warning('LINE webhook: Channel secret not configured', ['bot_id' => $bot->id]);
            return response()->json(['message' => 'Bot channel secret not configured'], 500);
        }

        try {
            $this->lineService->validateSignature(
                $request->getContent(),
                $signature,
                $bot->channel_secret
            );
        } catch (\Exception $e) {
            Log::warning('LINE webhook: Invalid signature', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        // Parse events from webhook body
        $body = $request->json()->all();
        $events = $this->lineService->parseEvents($body);

        if (empty($events)) {
            // This can happen with verification requests - just return OK
            return response()->json(['message' => 'OK']);
        }

        // Log webhook received
        Log::info('LINE webhook received', [
            'bot_id' => $bot->id,
            'event_count' => count($events),
        ]);

        // Dispatch job for each event
        foreach ($events as $event) {
            ProcessLINEWebhook::dispatch($bot, $event)
                ->onQueue('webhooks');
        }

        // Return 200 OK immediately - LINE requires fast response
        return response()->json(['message' => 'OK']);
    }

    /**
     * Find bot by webhook token.
     */
    protected function findBotByToken(string $token): ?Bot
    {
        // Build the expected webhook URL
        $webhookUrl = config('app.url') . '/webhook/' . $token;

        return Bot::where('webhook_url', $webhookUrl)
            ->where('channel_type', 'line')
            ->first();
    }
}
