<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bot\StoreBotRequest;
use App\Http\Requests\Bot\UpdateBotRequest;
use App\Http\Resources\BotResource;
use App\Models\Bot;
use App\Services\AIService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BotController extends Controller
{
    /**
     * Cache TTL for bot list (5 minutes)
     */
    protected const CACHE_TTL = 300;

    /**
     * List all bots for the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 15);
        $cacheKey = "user:{$user->id}:bots:page:{$page}:per:{$perPage}";

        $bots = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user, $perPage) {
            return $user->bots()
                ->with(['settings', 'defaultFlow'])
                ->latest()
                ->paginate($perPage);
        });

        return BotResource::collection($bots);
    }

    /**
     * Invalidate user's bot list cache.
     */
    protected function invalidateBotListCache(int $userId): void
    {
        // Clear all pages of bot list cache for this user
        // Using pattern-based clearing for database cache driver
        Cache::forget("user:{$userId}:bots:page:1:per:15");
        Cache::forget("user:{$userId}:bots:page:1:per:10");
        Cache::forget("user:{$userId}:bots:page:1:per:20");
    }

    /**
     * Create a new bot.
     */
    public function store(StoreBotRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $channelType = $validated['channel_type'];

        $bot = $request->user()->bots()->create([
            ...$validated,
            'webhook_url' => $this->generateWebhookUrl($channelType),
            'status' => 'inactive',
        ]);

        // Auto-create Base Flow for the new bot
        // Note: Model is NOT stored in Flow - it uses Bot Connection Settings (primary_chat_model)
        $baseFlow = $bot->flows()->create([
            'name' => 'Base Flow',
            'description' => 'Flow เริ่มต้นของ Bot - ใช้ตอบกลับเมื่อไม่มี Flow อื่นที่เหมาะสม',
            'system_prompt' => $this->getDefaultBaseFlowPrompt($bot),
            'temperature' => 0.7,
            'max_tokens' => 2048,
            'agentic_mode' => false,
            'max_tool_calls' => 10,
            'enabled_tools' => [],
            'language' => 'th',
            'is_default' => true,
        ]);

        $bot->update(['default_flow_id' => $baseFlow->id]);

        // Auto setup Telegram webhook if token is provided
        $webhookSetup = null;
        if ($channelType === 'telegram' && !empty($bot->channel_access_token)) {
            $webhookSetup = $this->setupTelegramWebhook($bot);
        }

        // Invalidate cache
        $this->invalidateBotListCache($request->user()->id);

        return response()->json([
            'message' => 'Bot created successfully',
            'data' => new BotResource($bot->load('defaultFlow')),
            'webhook_setup' => $webhookSetup,
        ], 201);
    }

    /**
     * Get default system prompt for Base Flow.
     */
    private function getDefaultBaseFlowPrompt(Bot $bot): string
    {
        return <<<PROMPT
คุณคือผู้ช่วย AI ของ {$bot->name} ที่เป็นมิตรและช่วยเหลือลูกค้าอย่างมืออาชีพ

## บทบาทของคุณ:
- ตอบคำถามอย่างชัดเจน กระชับ และเป็นมิตร
- ให้ข้อมูลที่ถูกต้องและเป็นประโยชน์
- หากไม่ทราบคำตอบ ให้ยอมรับตรงๆ และแนะนำวิธีหาข้อมูลเพิ่มเติม

## แนวทางการสื่อสาร:
- ใช้ภาษาที่สุภาพและเข้าใจง่าย
- ตอบในภาษาเดียวกับที่ลูกค้าใช้
- ถามคำถามเพื่อทำความเข้าใจหากข้อมูลไม่ชัดเจน
PROMPT;
    }

    /**
     * Get a specific bot.
     */
    public function show(Request $request, Bot $bot): BotResource
    {
        $this->authorize('view', $bot);

        return new BotResource($bot->load(['settings', 'defaultFlow']));
    }

    /**
     * Update a bot.
     */
    public function update(UpdateBotRequest $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validated();

        // Check if Telegram token changed (need to re-setup webhook)
        $telegramTokenChanged = $bot->channel_type === 'telegram'
            && isset($validated['channel_access_token'])
            && $validated['channel_access_token'] !== $bot->channel_access_token;

        $bot->update($validated);

        // Re-setup webhook if Telegram token changed
        $webhookSetup = null;
        if ($telegramTokenChanged && !empty($bot->channel_access_token)) {
            $webhookSetup = $this->setupTelegramWebhook($bot);
        }

        // Invalidate cache
        $this->invalidateBotListCache($request->user()->id);

        return response()->json([
            'message' => 'Bot updated successfully',
            'data' => new BotResource($bot->fresh()),
            'webhook_setup' => $webhookSetup,
        ]);
    }

    /**
     * Delete a bot.
     */
    public function destroy(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('delete', $bot);

        $bot->delete();

        // Invalidate cache
        $this->invalidateBotListCache($request->user()->id);

        return response()->json([
            'message' => 'Bot deleted successfully',
        ]);
    }

    /**
     * Get the webhook URL for a bot.
     */
    public function webhookUrl(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        return response()->json([
            'webhook_url' => $bot->webhook_url,
            'channel_type' => $bot->channel_type,
        ]);
    }

    /**
     * Test bot with a sample message.
     */
    public function test(Request $request, Bot $bot, AIService $aiService): JsonResponse
    {
        $this->authorize('update', $bot);

        $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $userMessage = $request->input('message');

        // Check if API key is available: User Settings > ENV
        $apiKey = $bot->user?->settings?->getOpenRouterApiKey()
            ?? config('services.openrouter.api_key');

        if (empty($apiKey)) {
            return response()->json([
                'message' => 'Test message received',
                'input' => $userMessage,
                'response' => 'กรุณาตั้งค่า OpenRouter API Key ที่หน้า Settings ก่อนทดสอบ',
                'bot_id' => $bot->id,
            ]);
        }

        try {
            $result = $aiService->testBotConfiguration($bot, $userMessage);

            return response()->json([
                'message' => 'Test completed successfully',
                'input' => $userMessage,
                'response' => $result['content'],
                'bot_id' => $bot->id,
                'model' => $result['model'],
                'usage' => $result['usage'],
                'cost' => $result['cost'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'AI service error',
                'input' => $userMessage,
                'response' => 'Failed to generate AI response: ' . $e->getMessage(),
                'bot_id' => $bot->id,
                'error' => true,
            ], 500);
        }
    }

    /**
     * Test LINE connection for a specific bot.
     * Verifies that the channel_access_token is valid by calling LINE Bot Info API.
     */
    public function testLineConnection(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        if ($bot->channel_type !== 'line') {
            return response()->json([
                'success' => false,
                'message' => 'Bot ไม่ได้ตั้งค่าสำหรับ LINE',
            ], 400);
        }

        if (empty($bot->channel_access_token)) {
            return response()->json([
                'success' => false,
                'message' => 'ยังไม่ได้ตั้งค่า Channel Access Token',
            ], 400);
        }

        try {
            // Call LINE Bot Info API to verify credentials
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $bot->channel_access_token,
            ])->timeout(10)->get('https://api.line.me/v2/bot/info');

            if ($response->successful()) {
                $botInfo = $response->json();
                return response()->json([
                    'success' => true,
                    'message' => 'เชื่อมต่อสำเร็จ',
                    'bot_info' => [
                        'display_name' => $botInfo['displayName'] ?? null,
                        'user_id' => $botInfo['userId'] ?? null,
                        'basic_id' => $botInfo['basicId'] ?? null,
                        'picture_url' => $botInfo['pictureUrl'] ?? null,
                        'premium_id' => $botInfo['premiumId'] ?? null,
                    ],
                ]);
            }

            // Handle specific LINE API errors
            $errorMessage = match ($response->status()) {
                401 => 'Channel Access Token ไม่ถูกต้องหรือหมดอายุ',
                403 => 'ไม่มีสิทธิ์เข้าถึง - กรุณาตรวจสอบการตั้งค่า Channel',
                429 => 'เกินอัตราการเรียกใช้ API - กรุณาลองใหม่ภายหลัง',
                default => 'ไม่สามารถเชื่อมต่อได้ - กรุณาตรวจสอบ Channel Access Token',
            };

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'error_code' => $response->status(),
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อ: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Regenerate webhook URL for a bot.
     */
    public function regenerateWebhook(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $bot->update([
            'webhook_url' => $this->generateWebhookUrl($bot->channel_type),
        ]);

        // Re-setup Telegram webhook with new URL
        $webhookSetup = null;
        if ($bot->channel_type === 'telegram' && !empty($bot->channel_access_token)) {
            $webhookSetup = $this->setupTelegramWebhook($bot);
        }

        return response()->json([
            'message' => 'Webhook URL regenerated successfully',
            'webhook_url' => $bot->webhook_url,
            'webhook_setup' => $webhookSetup,
        ]);
    }

    /**
     * Generate a unique webhook URL.
     */
    private function generateWebhookUrl(string $channelType = 'line'): string
    {
        $token = Str::random(32);

        // Platform-specific webhook paths
        $path = match ($channelType) {
            'telegram' => '/webhook/telegram/',
            default => '/webhook/',
        };

        return config('app.url') . $path . $token;
    }

    /**
     * Setup Telegram webhook automatically.
     */
    private function setupTelegramWebhook(Bot $bot): bool
    {
        try {
            $telegramService = app(TelegramService::class);

            // Validate token first by calling getMe
            $telegramService->getMe($bot);

            // Set webhook
            $success = $telegramService->setWebhook($bot, $bot->webhook_url);

            if ($success) {
                Log::info('Telegram webhook setup successful', [
                    'bot_id' => $bot->id,
                    'webhook_url' => $bot->webhook_url,
                ]);
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('Telegram webhook setup failed', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
