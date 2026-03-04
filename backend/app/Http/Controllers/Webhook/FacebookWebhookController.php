<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessFacebookWebhook;
use App\Models\Bot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class FacebookWebhookController extends Controller
{
    /**
     * Verify webhook subscription (Facebook verification challenge).
     *
     * Facebook sends a GET request to verify the webhook URL.
     * We must respond with the hub.challenge value if the verify token matches.
     */
    public function verify(Request $request, string $token): Response
    {
        $bot = $this->findBotByToken($token);

        if (! $bot) {
            Log::warning('Facebook webhook verify: Invalid token', ['token' => substr($token, 0, 8).'...']);

            return response('Bot not found', 404);
        }

        $mode = $request->query('hub_mode');
        $verifyToken = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // Verify token matches bot's channel_secret (used as verify token for Facebook)
        // Note: For Facebook, we use channel_secret to store the verify token
        // and page_access_token for the Page Access Token
        if ($mode === 'subscribe' && $verifyToken === $bot->channel_secret) {
            Log::info('Facebook webhook verified', ['bot_id' => $bot->id]);

            return response($challenge, 200);
        }

        Log::warning('Facebook webhook verify: Token mismatch', [
            'bot_id' => $bot->id,
            'mode' => $mode,
        ]);

        return response('Invalid verify token', 403);
    }

    /**
     * Handle incoming webhook events from Facebook Messenger.
     */
    public function handle(Request $request, string $token): JsonResponse
    {
        $bot = $this->findBotByToken($token);

        if (! $bot) {
            Log::warning('Facebook webhook: Invalid token', ['token' => substr($token, 0, 8).'...']);

            return response()->json(['error' => 'Invalid webhook token'], 404);
        }

        // Validate X-Hub-Signature-256 header
        if (! $this->validateSignature($request, $bot)) {
            Log::warning('Facebook webhook: Invalid signature', ['bot_id' => $bot->id]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Parse webhook body
        $body = $request->json()->all();

        // Facebook sends 'object' field to identify the type
        if (($body['object'] ?? null) !== 'page') {
            Log::debug('Facebook webhook: Not a page event', [
                'bot_id' => $bot->id,
                'object' => $body['object'] ?? null,
            ]);

            return response()->json(['status' => 'ok']);
        }

        $entries = $body['entry'] ?? [];

        if (empty($entries)) {
            Log::debug('Facebook webhook: No entries', ['bot_id' => $bot->id]);

            return response()->json(['status' => 'ok']);
        }

        // Log webhook received
        Log::info('Facebook webhook received', [
            'bot_id' => $bot->id,
            'entry_count' => count($entries),
        ]);

        // Dispatch job for async processing
        ProcessFacebookWebhook::dispatch($bot, $body)
            ->onQueue('webhooks');

        // Return 200 OK immediately - Facebook requires fast response
        return response()->json(['status' => 'ok']);
    }

    /**
     * Find bot by webhook token.
     */
    protected function findBotByToken(string $token): ?Bot
    {
        // Build the expected webhook URL (using /api/webhook/ path for proxy compatibility)
        $webhookUrl = config('app.url').'/api/webhook/facebook/'.$token;

        return Bot::where('webhook_url', $webhookUrl)
            ->where('channel_type', 'facebook')
            ->first();
    }

    /**
     * Validate X-Hub-Signature-256 header from Facebook.
     *
     * Facebook signs the request payload with the App Secret.
     * We need to verify the signature to ensure the request is authentic.
     */
    protected function validateSignature(Request $request, Bot $bot): bool
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (! $signature) {
            Log::debug('Facebook webhook: Missing signature header', ['bot_id' => $bot->id]);

            return false;
        }

        // Get app secret from environment (shared across all Facebook bots)
        $appSecret = config('services.facebook.app_secret');

        if (! $appSecret) {
            Log::warning('Facebook webhook: App secret not configured');

            return false;
        }

        $payload = $request->getContent();
        $expectedSignature = 'sha256='.hash_hmac('sha256', $payload, $appSecret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get the type of messaging event for logging.
     */
    protected function getEventType(array $messaging): string
    {
        return match (true) {
            isset($messaging['message']) => 'message',
            isset($messaging['postback']) => 'postback',
            isset($messaging['read']) => 'read',
            isset($messaging['delivery']) => 'delivery',
            isset($messaging['optin']) => 'optin',
            isset($messaging['referral']) => 'referral',
            isset($messaging['reaction']) => 'reaction',
            default => 'unknown',
        };
    }
}
