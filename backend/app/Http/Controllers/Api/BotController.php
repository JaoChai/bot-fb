<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bot\StoreBotRequest;
use App\Http\Requests\Bot\UpdateBotRequest;
use App\Http\Resources\BotResource;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Bot;
use App\Services\AIService;
use App\Services\ModelCapabilityService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BotController extends Controller
{
    use ApiResponseTrait;
    /**
     * List all bots accessible by the authenticated user.
     * Owner sees owned bots, Admin sees assigned bots.
     *
     * NOTE: No server-side caching for bot list.
     * Bot list changes frequently (status toggles, creates, deletes) and must
     * reflect changes immediately. The query is simple (user's bots with relations)
     * and caching caused sync issues where deleted/updated bots still appeared.
     * Frontend uses React Query with short staleTime for client-side caching.
     *
     * @OA\Get(
     *     path="/api/bots",
     *     summary="List all bots",
     *     description="Returns paginated list of bots accessible by the authenticated user. Owners see their owned bots, Admins see assigned bots.",
     *     operationId="listBots",
     *     tags={"Bots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Number of items per page",
     *         required=false,
     *         @OA\Schema(type="integer", default=15, minimum=1, maximum=100)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/Bot")
     *             ),
     *             @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 15);

        $bots = $user->accessibleBots()
            ->with(['settings', 'defaultFlow'])
            ->latest()
            ->paginate($perPage);

        return BotResource::collection($bots);
    }

    /**
     * Create a new bot.
     *
     * @OA\Post(
     *     path="/api/bots",
     *     summary="Create a new bot",
     *     description="Creates a new bot with auto-generated webhook URL and Base Flow. For Telegram bots, webhook is automatically configured.",
     *     operationId="createBot",
     *     tags={"Bots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "channel_type"},
     *             @OA\Property(property="name", type="string", maxLength=255, example="My Support Bot"),
     *             @OA\Property(property="channel_type", type="string", enum={"line", "telegram", "facebook"}, example="line"),
     *             @OA\Property(property="channel_access_token", type="string", description="Channel access token for the messaging platform"),
     *             @OA\Property(property="channel_secret", type="string", description="Channel secret (LINE only)"),
     *             @OA\Property(property="description", type="string", maxLength=1000)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Bot created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Bot created successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Bot"),
     *             @OA\Property(property="webhook_setup", type="boolean", nullable=true, description="Telegram webhook setup result")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
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

        return $this->created([
            'bot' => new BotResource($bot->load('defaultFlow')),
            'webhook_setup' => $webhookSetup,
        ], 'Bot created successfully');
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
     *
     * @OA\Get(
     *     path="/api/bots/{bot}",
     *     summary="Get a specific bot",
     *     description="Returns detailed information about a specific bot including settings and default flow.",
     *     operationId="getBot",
     *     tags={"Bots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Bot")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Bot not found")
     * )
     */
    public function show(Request $request, Bot $bot): BotResource
    {
        $this->authorize('view', $bot);

        return new BotResource($bot->load(['settings', 'defaultFlow']));
    }

    /**
     * Update a bot.
     *
     * @OA\Put(
     *     path="/api/bots/{bot}",
     *     summary="Update a bot",
     *     description="Updates bot information. For Telegram bots, changing the token will re-configure the webhook.",
     *     operationId="updateBot",
     *     tags={"Bots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", maxLength=255),
     *             @OA\Property(property="description", type="string", maxLength=1000),
     *             @OA\Property(property="channel_access_token", type="string"),
     *             @OA\Property(property="channel_secret", type="string"),
     *             @OA\Property(property="status", type="string", enum={"active", "inactive"})
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Bot updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Bot updated successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Bot"),
     *             @OA\Property(property="webhook_setup", type="boolean", nullable=true)
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Bot not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(UpdateBotRequest $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validated();

        // Track model changes for cache invalidation
        $oldPrimaryModel = $bot->primary_chat_model;
        $oldFallbackModel = $bot->fallback_chat_model;

        // Check if Telegram token changed (need to re-setup webhook)
        $telegramTokenChanged = $bot->channel_type === 'telegram'
            && isset($validated['channel_access_token'])
            && $validated['channel_access_token'] !== $bot->channel_access_token;

        $bot->update($validated);

        // Warm cache for new models if they changed
        $this->warmModelCacheIfChanged(
            $oldPrimaryModel,
            $oldFallbackModel,
            $bot->primary_chat_model,
            $bot->fallback_chat_model
        );

        // Re-setup webhook if Telegram token changed
        $webhookSetup = null;
        if ($telegramTokenChanged && !empty($bot->channel_access_token)) {
            $webhookSetup = $this->setupTelegramWebhook($bot);
        }

        return $this->success([
            'bot' => new BotResource($bot->fresh()),
            'webhook_setup' => $webhookSetup,
        ], 'Bot updated successfully');
    }

    /**
     * Warm model capability cache when bot models change.
     */
    protected function warmModelCacheIfChanged(
        ?string $oldPrimary,
        ?string $oldFallback,
        ?string $newPrimary,
        ?string $newFallback
    ): void {
        $modelService = app(ModelCapabilityService::class);
        $modelsToWarm = [];

        // Check if primary model changed
        if ($newPrimary && $newPrimary !== $oldPrimary) {
            $modelsToWarm[] = $newPrimary;
        }

        // Check if fallback model changed
        if ($newFallback && $newFallback !== $oldFallback) {
            $modelsToWarm[] = $newFallback;
        }

        // Warm cache for new models (fetch capabilities proactively)
        foreach (array_unique($modelsToWarm) as $model) {
            try {
                $modelService->getCapabilities($model);
                Log::info('Warmed model capability cache', ['model' => $model]);
            } catch (\Throwable $e) {
                Log::warning('Failed to warm model cache', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Delete a bot.
     */
    public function destroy(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('delete', $bot);

        $bot->delete();

        return $this->success(null, 'Bot deleted successfully');
    }

    /**
     * Get the webhook URL for a bot.
     */
    public function webhookUrl(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        return $this->success([
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
            return $this->success([
                'input' => $userMessage,
                'response' => 'กรุณาตั้งค่า OpenRouter API Key ที่หน้า Settings ก่อนทดสอบ',
                'bot_id' => $bot->id,
            ], 'Test message received');
        }

        try {
            $result = $aiService->testBotConfiguration($bot, $userMessage);

            return $this->success([
                'input' => $userMessage,
                'response' => $result['content'],
                'bot_id' => $bot->id,
                'model' => $result['model'],
                'usage' => $result['usage'],
                'cost' => $result['cost'],
            ], 'Test completed successfully');
        } catch (\Exception $e) {
            return $this->error('AI service error', 500, [
                'input' => $userMessage,
                'response' => 'Failed to generate AI response: ' . $e->getMessage(),
                'bot_id' => $bot->id,
            ]);
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
            return $this->error('Bot ไม่ได้ตั้งค่าสำหรับ LINE', 400, ['success' => false]);
        }

        if (empty($bot->channel_access_token)) {
            return $this->error('ยังไม่ได้ตั้งค่า Channel Access Token', 400, ['success' => false]);
        }

        try {
            // Call LINE Bot Info API to verify credentials
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $bot->channel_access_token,
            ])->timeout(10)->get('https://api.line.me/v2/bot/info');

            if ($response->successful()) {
                $botInfo = $response->json();
                return $this->success([
                    'success' => true,
                    'bot_info' => [
                        'display_name' => $botInfo['displayName'] ?? null,
                        'user_id' => $botInfo['userId'] ?? null,
                        'basic_id' => $botInfo['basicId'] ?? null,
                        'picture_url' => $botInfo['pictureUrl'] ?? null,
                        'premium_id' => $botInfo['premiumId'] ?? null,
                    ],
                ], 'เชื่อมต่อสำเร็จ');
            }

            // Handle specific LINE API errors
            $errorMessage = match ($response->status()) {
                401 => 'Channel Access Token ไม่ถูกต้องหรือหมดอายุ',
                403 => 'ไม่มีสิทธิ์เข้าถึง - กรุณาตรวจสอบการตั้งค่า Channel',
                429 => 'เกินอัตราการเรียกใช้ API - กรุณาลองใหม่ภายหลัง',
                default => 'ไม่สามารถเชื่อมต่อได้ - กรุณาตรวจสอบ Channel Access Token',
            };

            return $this->error($errorMessage, 400, [
                'success' => false,
                'error_code' => $response->status(),
            ]);

        } catch (\Exception $e) {
            return $this->serverError('เกิดข้อผิดพลาดในการเชื่อมต่อ: ' . $e->getMessage());
        }
    }

    /**
     * Test Telegram connection and webhook status for a specific bot.
     */
    public function testTelegramConnection(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        if ($bot->channel_type !== 'telegram') {
            return $this->error('Bot ไม่ได้ตั้งค่าสำหรับ Telegram', 400, ['success' => false]);
        }

        if (empty($bot->channel_access_token)) {
            return $this->error('ยังไม่ได้ตั้งค่า Bot Token', 400, ['success' => false]);
        }

        try {
            $telegramService = app(TelegramService::class);

            // 1. Validate token by calling getMe
            $botInfo = $telegramService->getMe($bot);

            // 2. Get current webhook info
            $webhookInfo = $telegramService->getWebhookInfo($bot);

            // 3. Check if webhook URL matches
            $currentWebhookUrl = $webhookInfo['url'] ?? '';
            $expectedWebhookUrl = $bot->webhook_url;
            $webhookMatches = $currentWebhookUrl === $expectedWebhookUrl;

            // 4. If webhook doesn't match, try to set it
            $webhookFixed = false;
            if (!$webhookMatches && !empty($expectedWebhookUrl)) {
                $webhookFixed = $telegramService->setWebhook($bot, $expectedWebhookUrl);
                if ($webhookFixed) {
                    $webhookInfo = $telegramService->getWebhookInfo($bot);
                }
            }

            return $this->success([
                'success' => true,
                'bot_info' => [
                    'username' => $botInfo['username'] ?? null,
                    'first_name' => $botInfo['first_name'] ?? null,
                    'can_join_groups' => $botInfo['can_join_groups'] ?? false,
                    'can_read_all_group_messages' => $botInfo['can_read_all_group_messages'] ?? false,
                ],
                'webhook_info' => [
                    'url' => $webhookInfo['url'] ?? null,
                    'has_custom_certificate' => $webhookInfo['has_custom_certificate'] ?? false,
                    'pending_update_count' => $webhookInfo['pending_update_count'] ?? 0,
                    'last_error_date' => $webhookInfo['last_error_date'] ?? null,
                    'last_error_message' => $webhookInfo['last_error_message'] ?? null,
                    'max_connections' => $webhookInfo['max_connections'] ?? null,
                ],
                'webhook_status' => [
                    'expected_url' => $expectedWebhookUrl,
                    'matches' => $currentWebhookUrl === ($webhookInfo['url'] ?? ''),
                    'was_fixed' => $webhookFixed,
                ],
            ], 'เชื่อมต่อสำเร็จ');

        } catch (\Exception $e) {
            return $this->error('Bot Token ไม่ถูกต้อง: ' . $e->getMessage(), 400, ['success' => false]);
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

        return $this->success([
            'webhook_url' => $bot->webhook_url,
            'webhook_setup' => $webhookSetup,
        ], 'Webhook URL regenerated successfully');
    }

    /**
     * Generate a unique webhook URL.
     * Uses /api/webhook/ path to work with custom domain proxy
     */
    private function generateWebhookUrl(string $channelType = 'line'): string
    {
        $token = Str::random(32);

        // Platform-specific webhook paths (under /api/ for proxy compatibility)
        $path = match ($channelType) {
            'telegram' => '/api/webhook/telegram/',
            default => '/api/webhook/',
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

    /**
     * Reveal bot credentials (owner only).
     * Returns the actual channel_access_token and channel_secret.
     *
     * @OA\Get(
     *     path="/api/bots/{bot}/credentials",
     *     summary="Get bot credentials",
     *     description="Returns the channel access token and secret. Owner only - admins cannot access credentials.",
     *     operationId="getBotCredentials",
     *     tags={"Bots"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Credentials retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="channel_access_token", type="string"),
     *                 @OA\Property(property="channel_secret", type="string", nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden - only owners can view credentials"),
     *     @OA\Response(response=404, description="Bot not found")
     * )
     */
    public function credentials(Bot $bot): JsonResponse
    {
        $this->authorize('viewCredentials', $bot);

        return $this->success([
            'channel_access_token' => $bot->channel_access_token,
            'channel_secret' => $bot->channel_secret,
        ]);
    }
}
