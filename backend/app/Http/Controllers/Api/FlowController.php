<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OpenRouterException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flow\StoreFlowRequest;
use App\Http\Requests\Flow\UpdateFlowRequest;
use App\Http\Resources\FlowListResource;
use App\Http\Resources\FlowResource;
use App\Http\Traits\ApiResponseTrait;
use App\Models\Bot;
use App\Models\Flow;
use App\Services\FlowCacheService;
use App\Services\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlowController extends Controller
{
    use ApiResponseTrait;

    public function __construct(
        protected FlowCacheService $flowCache
    ) {}

    /**
     * List all flows for a bot.
     * Uses FlowListResource for slim payload (no system_prompt, enabled_tools).
     *
     * @OA\Get(
     *     path="/api/bots/{bot}/flows",
     *     summary="List all flows for a bot",
     *     description="Returns paginated list of flows for a specific bot. Base/default flow is always listed first.",
     *     operationId="listFlows",
     *     tags={"Flows"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
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
     *                 @OA\Items(ref="#/components/schemas/FlowList")
     *             ),
     *             @OA\Property(property="meta", ref="#/components/schemas/PaginationMeta")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Bot not found")
     * )
     */
    public function index(Request $request, Bot $bot): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);

        $flows = $bot->flows()
            ->with('knowledgeBases:knowledge_bases.id')  // Only load IDs for count
            ->orderByDesc('is_default')  // Base flow always first
            ->latest()
            ->paginate($request->input('per_page', 15));

        return FlowListResource::collection($flows);
    }

    /**
     * Create a new flow for a bot.
     *
     * @OA\Post(
     *     path="/api/bots/{bot}/flows",
     *     summary="Create a new flow",
     *     description="Creates a new flow for a bot. If it's the first flow or marked as default, it becomes the default flow.",
     *     operationId="createFlow",
     *     tags={"Flows"},
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
     *             required={"name"},
     *             @OA\Property(property="name", type="string", maxLength=255, example="Customer Support Flow"),
     *             @OA\Property(property="description", type="string", maxLength=1000),
     *             @OA\Property(property="system_prompt", type="string", description="AI system prompt"),
     *             @OA\Property(property="temperature", type="number", format="float", minimum=0, maximum=2, default=0.7),
     *             @OA\Property(property="max_tokens", type="integer", minimum=100, maximum=32000, default=2048),
     *             @OA\Property(property="agentic_mode", type="boolean", default=false),
     *             @OA\Property(property="max_tool_calls", type="integer", minimum=1, maximum=50, default=10),
     *             @OA\Property(property="enabled_tools", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="language", type="string", enum={"th", "en", "zh", "ja", "ko"}, default="th"),
     *             @OA\Property(property="is_default", type="boolean", default=false),
     *             @OA\Property(
     *                 property="knowledge_bases",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="kb_top_k", type="integer", default=5),
     *                     @OA\Property(property="kb_similarity_threshold", type="number", format="float", default=0.7)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Flow created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Flow created successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Flow")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreFlowRequest $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $data = $request->validated();

        // Cast boolean fields for PostgreSQL compatibility
        $data = $this->castBooleanFields($data);

        // If this is marked as default, unset other defaults
        if ($data['is_default'] ?? false) {
            $bot->flows()->update(['is_default' => false]);
        }

        // If this is the first flow, make it default
        if (!$bot->flows()->exists()) {
            $data['is_default'] = true;
        }

        // Extract knowledge_bases before creating flow
        $knowledgeBases = $data['knowledge_bases'] ?? [];
        unset($data['knowledge_bases']);

        $flow = $bot->flows()->create($data);

        // Sync knowledge bases with pivot data
        if (!empty($knowledgeBases)) {
            $syncData = [];
            foreach ($knowledgeBases as $kb) {
                $syncData[$kb['id']] = [
                    'kb_top_k' => $kb['kb_top_k'] ?? 5,
                    'kb_similarity_threshold' => $kb['kb_similarity_threshold'] ?? 0.7,
                ];
            }
            $flow->knowledgeBases()->sync($syncData);
        }

        // Update bot's default flow if this is the default
        if ($flow->is_default) {
            $bot->update(['default_flow_id' => $flow->id]);
        }

        // Invalidate cache for this bot
        $this->flowCache->invalidateBot($bot->id);

        return $this->created(new FlowResource($flow->load('knowledgeBases')), 'Flow created successfully');
    }

    /**
     * Get a specific flow.
     *
     * @OA\Get(
     *     path="/api/bots/{bot}/flows/{flow}",
     *     summary="Get a specific flow",
     *     description="Returns detailed information about a specific flow including knowledge bases.",
     *     operationId="getFlow",
     *     tags={"Flows"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="flow",
     *         in="path",
     *         description="Flow ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/Flow")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Flow not found")
     * )
     */
    public function show(Request $request, Bot $bot, Flow $flow): FlowResource
    {
        $this->authorize('view', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        return new FlowResource($flow->load('knowledgeBases'));
    }

    /**
     * Update a flow.
     *
     * @OA\Put(
     *     path="/api/bots/{bot}/flows/{flow}",
     *     summary="Update a flow",
     *     description="Updates flow configuration. Setting is_default=true will unset other flows as default.",
     *     operationId="updateFlow",
     *     tags={"Flows"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="flow",
     *         in="path",
     *         description="Flow ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", maxLength=255),
     *             @OA\Property(property="description", type="string", maxLength=1000),
     *             @OA\Property(property="system_prompt", type="string"),
     *             @OA\Property(property="temperature", type="number", format="float", minimum=0, maximum=2),
     *             @OA\Property(property="max_tokens", type="integer", minimum=100, maximum=32000),
     *             @OA\Property(property="agentic_mode", type="boolean"),
     *             @OA\Property(property="max_tool_calls", type="integer", minimum=1, maximum=50),
     *             @OA\Property(property="enabled_tools", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="language", type="string", enum={"th", "en", "zh", "ja", "ko"}),
     *             @OA\Property(property="is_default", type="boolean"),
     *             @OA\Property(
     *                 property="knowledge_bases",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="kb_top_k", type="integer"),
     *                     @OA\Property(property="kb_similarity_threshold", type="number", format="float")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Flow updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Flow updated successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Flow")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Flow not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(UpdateFlowRequest $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        $data = $request->validated();

        // Cast boolean fields for PostgreSQL compatibility
        $data = $this->castBooleanFields($data);

        // If setting as default, unset other defaults
        if ($data['is_default'] ?? false) {
            $bot->flows()->where('id', '!=', $flow->id)->update(['is_default' => false]);
        }

        // Extract knowledge_bases before updating flow
        $knowledgeBases = $data['knowledge_bases'] ?? null;
        unset($data['knowledge_bases']);

        $flow->update($data);

        // Sync knowledge bases if provided
        if ($knowledgeBases !== null) {
            $syncData = [];
            foreach ($knowledgeBases as $kb) {
                $syncData[$kb['id']] = [
                    'kb_top_k' => $kb['kb_top_k'] ?? 5,
                    'kb_similarity_threshold' => $kb['kb_similarity_threshold'] ?? 0.7,
                ];
            }
            $flow->knowledgeBases()->sync($syncData);
        }

        // Update bot's default flow if this is now the default
        if ($flow->is_default) {
            $bot->update(['default_flow_id' => $flow->id]);
        }

        // Refresh to get updated data without full reload
        $flow->refresh();

        // Invalidate cache (default flow may have changed)
        $this->flowCache->invalidateBot($bot->id);

        return $this->success(new FlowResource($flow->load('knowledgeBases')), 'Flow updated successfully');
    }

    /**
     * Delete a flow.
     */
    public function destroy(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        // Prevent deleting Base Flow (default flow)
        if ($flow->is_default) {
            return $this->validationError('ไม่สามารถลบ Base Flow ได้ Base Flow เป็น Flow หลักของ Bot หากต้องการลบ กรุณาตั้ง Flow อื่นเป็น Default ก่อน');
        }

        // Don't allow deleting the only flow (check if other flows exist)
        if (!$bot->flows()->where('id', '!=', $flow->id)->exists()) {
            return $this->validationError('Cannot delete the only flow. Create another flow first.');
        }

        $flow->delete();

        // Invalidate cache for this bot
        $this->flowCache->invalidateBot($bot->id);

        return $this->success(null, 'Flow deleted successfully');
    }

    /**
     * Set a flow as the default for a bot.
     *
     * @OA\Post(
     *     path="/api/bots/{bot}/flows/{flow}/set-default",
     *     summary="Set flow as default",
     *     description="Sets this flow as the default flow for the bot. The previous default flow will be unset.",
     *     operationId="setDefaultFlow",
     *     tags={"Flows"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="flow",
     *         in="path",
     *         description="Flow ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Flow set as default successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Flow set as default successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Flow")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Flow not found")
     * )
     */
    public function setDefault(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        DB::transaction(function () use ($bot, $flow) {
            // Lock all flows for this bot to prevent race condition
            Flow::where('bot_id', $bot->id)
                ->lockForUpdate()
                ->update(['is_default' => false]);

            // Set this flow as default
            $flow->update(['is_default' => true]);

            // Update bot's default_flow_id reference
            $bot->update(['default_flow_id' => $flow->id]);
        });

        // Refresh to get updated data
        $flow->refresh();

        // Invalidate default flow cache
        $this->flowCache->invalidateDefaultFlow($bot->id);

        return $this->success(new FlowResource($flow), 'Flow set as default successfully');
    }

    /**
     * Duplicate a flow.
     */
    public function duplicate(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        // Eager load knowledgeBases to prevent N+1 in the loop below
        $flow->loadMissing('knowledgeBases');

        $newFlow = $flow->replicate();
        $newFlow->name = $flow->name . ' (Copy)';
        $newFlow->is_default = false;
        $newFlow->save();

        // Copy knowledge base associations
        $kbData = [];
        foreach ($flow->knowledgeBases as $kb) {
            $kbData[$kb->id] = [
                'kb_top_k' => $kb->pivot->kb_top_k,
                'kb_similarity_threshold' => $kb->pivot->kb_similarity_threshold,
            ];
        }
        $newFlow->knowledgeBases()->sync($kbData);

        // Invalidate cache (new flow added)
        $this->flowCache->invalidateBot($bot->id);

        return $this->created(new FlowResource($newFlow->load('knowledgeBases')), 'Flow duplicated successfully');
    }

    /**
     * Test a flow with a message using the Chat Emulator.
     * Uses the flow's system_prompt and model settings to generate an AI response.
     */
    public function test(Request $request, Bot $bot, Flow $flow, OpenRouterService $openRouter): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        $request->validate([
            'message' => 'required|string|max:2000',
            'conversation_history' => 'array',
            'conversation_history.*.role' => 'required|string|in:user,assistant',
            'conversation_history.*.content' => 'required|string',
        ]);

        $userMessage = $request->input('message');
        $conversationHistory = $request->input('conversation_history', []);

        // Get API key: User Settings > ENV
        $apiKey = $bot->user?->settings?->getOpenRouterApiKey() ?? config('services.openrouter.api_key');

        if (empty($apiKey)) {
            return $this->validationError('ไม่พบ OpenRouter API Key กรุณาตั้งค่าในหน้า Settings', ['error_code' => 'NO_API_KEY']);
        }

        // Build messages array for OpenRouter
        $messages = [];

        // Add system prompt
        $systemPrompt = $flow->system_prompt ?: $this->getDefaultSystemPrompt($bot);
        $messages[] = [
            'role' => 'system',
            'content' => $systemPrompt,
        ];

        // Add conversation history
        foreach ($conversationHistory as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        // Add current user message
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage,
        ];

        try {
            // Use model from Bot Connection Settings (not from Flow)
            $chatModel = $bot->primary_chat_model ?? config('services.openrouter.default_model');

            $result = $openRouter->chat(
                messages: $messages,
                model: $chatModel,
                temperature: $flow->temperature ? (float) $flow->temperature : 0.7,
                maxTokens: $flow->max_tokens ?? 2048,
                useFallback: true,
                apiKeyOverride: $apiKey,
                fallbackModelOverride: $bot->fallback_chat_model
            );

            Log::info('Flow test successful', [
                'flow_id' => $flow->id,
                'bot_id' => $bot->id,
                'model' => $result['model'],
                'tokens' => $result['usage']['total_tokens'] ?? 0,
            ]);

            return $this->success([
                'success' => true,
                'response' => $result['content'],
                'model' => $result['model'],
                'usage' => $result['usage'],
            ]);
        } catch (OpenRouterException $e) {
            Log::error('Flow test failed', [
                'flow_id' => $flow->id,
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
                'status' => $e->getCode(),
            ]);

            $errorMessage = 'เกิดข้อผิดพลาดในการเชื่อมต่อ AI';
            if ($e->isAuthError()) {
                $errorMessage = 'API Key ไม่ถูกต้อง กรุณาตรวจสอบใน Settings';
            } elseif ($e->isRateLimited()) {
                $errorMessage = 'เกินอัตราการใช้งาน กรุณารอสักครู่';
            }

            $statusCode = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
            return $this->error($errorMessage, $statusCode, [
                'success' => false,
                'error_code' => $e->isAuthError() ? 'AUTH_ERROR' : ($e->isRateLimited() ? 'RATE_LIMITED' : 'API_ERROR'),
            ]);
        } catch (\Exception $e) {
            Log::error('Flow test unexpected error', [
                'flow_id' => $flow->id,
                'error' => $e->getMessage(),
            ]);

            return $this->serverError('เกิดข้อผิดพลาดที่ไม่คาดคิด');
        }
    }

    /**
     * Get default system prompt for testing.
     */
    protected function getDefaultSystemPrompt(Bot $bot): string
    {
        return <<<PROMPT
คุณเป็นผู้ช่วย AI สำหรับ {$bot->name}
ตอบอย่างเป็นมิตร ชัดเจน และกระชับ
ตอบในภาษาเดียวกับที่ผู้ใช้ถาม
หากไม่ทราบคำตอบ ให้บอกตรงๆ
PROMPT;
    }

    /**
     * Get available prompt templates.
     */
    public function templates(): JsonResponse
    {
        $templates = [
            [
                'id' => 'customer_support',
                'name' => 'Customer Support Assistant',
                'description' => 'Helpful, empathetic support agent',
                'system_prompt' => $this->getCustomerSupportPrompt(),
                'temperature' => 0.7,
                'language' => 'th',
            ],
            [
                'id' => 'sales_assistant',
                'name' => 'Sales Assistant',
                'description' => 'Persuasive yet helpful sales representative',
                'system_prompt' => $this->getSalesPrompt(),
                'temperature' => 0.8,
                'language' => 'th',
            ],
            [
                'id' => 'faq_bot',
                'name' => 'FAQ Bot',
                'description' => 'Concise, accurate information provider',
                'system_prompt' => $this->getFaqPrompt(),
                'temperature' => 0.3,
                'language' => 'th',
            ],
            [
                'id' => 'general_assistant',
                'name' => 'General Assistant',
                'description' => 'Versatile helper for various tasks',
                'system_prompt' => $this->getGeneralPrompt(),
                'temperature' => 0.7,
                'language' => 'th',
            ],
        ];

        return $this->success($templates);
    }

    /**
     * Ensure the flow belongs to the bot.
     */
    protected function ensureFlowBelongsToBot(Flow $flow, Bot $bot): void
    {
        if ($flow->bot_id !== $bot->id) {
            abort(404, 'Flow not found');
        }
    }

    /**
     * Cast boolean fields for PostgreSQL compatibility.
     * Ensures values are proper PHP booleans before database insert.
     */
    protected function castBooleanFields(array $data): array
    {
        if (array_key_exists('agentic_mode', $data)) {
            $data['agentic_mode'] = filter_var($data['agentic_mode'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('is_default', $data)) {
            $data['is_default'] = filter_var($data['is_default'], FILTER_VALIDATE_BOOLEAN);
        }
        return $data;
    }

    protected function getCustomerSupportPrompt(): string
    {
        return <<<'PROMPT'
คุณเป็นเจ้าหน้าที่ดูแลลูกค้าที่เป็นมิตรและเข้าใจความรู้สึกของลูกค้า

## หน้าที่ของคุณ:
- ช่วยเหลือลูกค้าด้วยความใส่ใจและเข้าใจ
- ตอบคำถามเกี่ยวกับผลิตภัณฑ์และบริการ
- แก้ไขปัญหาอย่างมีประสิทธิภาพ
- หากไม่แน่ใจ ให้สอบถามข้อมูลเพิ่มเติม

## แนวทางการสนทนา:
- ใช้ภาษาที่สุภาพและเป็นกันเอง
- แสดงความเห็นอกเห็นใจเมื่อลูกค้ามีปัญหา
- ตอบให้กระชับแต่ครบถ้วน
- ขอบคุณลูกค้าที่ติดต่อเข้ามา
PROMPT;
    }

    protected function getSalesPrompt(): string
    {
        return <<<'PROMPT'
คุณเป็นที่ปรึกษาด้านการขายที่มีความเชี่ยวชาญ

## หน้าที่ของคุณ:
- ให้ข้อมูลเกี่ยวกับผลิตภัณฑ์และบริการ
- แนะนำสินค้าที่เหมาะสมกับความต้องการ
- ตอบคำถามและแก้ข้อสงสัยของลูกค้า
- ช่วยลูกค้าตัดสินใจซื้อ

## แนวทางการสนทนา:
- เป็นมิตรและน่าเชื่อถือ
- เน้นประโยชน์ที่ลูกค้าจะได้รับ
- ไม่กดดันลูกค้า
- ให้ข้อมูลที่ถูกต้องและโปร่งใส
PROMPT;
    }

    protected function getFaqPrompt(): string
    {
        return <<<'PROMPT'
คุณเป็นระบบตอบคำถามอัตโนมัติ

## หน้าที่ของคุณ:
- ตอบคำถามที่พบบ่อยอย่างตรงประเด็น
- ให้ข้อมูลที่ถูกต้องและเป็นปัจจุบัน
- นำทางลูกค้าไปยังข้อมูลที่ต้องการ

## แนวทางการสนทนา:
- ตอบสั้นกระชับ ตรงประเด็น
- ใช้ข้อมูลจาก Knowledge Base เป็นหลัก
- หากไม่พบคำตอบ ให้แนะนำติดต่อเจ้าหน้าที่
PROMPT;
    }

    protected function getGeneralPrompt(): string
    {
        return <<<'PROMPT'
คุณเป็นผู้ช่วยอัจฉริยะที่พร้อมช่วยเหลือในทุกเรื่อง

## หน้าที่ของคุณ:
- ตอบคำถามทั่วไป
- ให้ข้อมูลและคำแนะนำ
- ช่วยแก้ปัญหาต่างๆ

## แนวทางการสนทนา:
- เป็นมิตรและเข้าถึงง่าย
- ตอบในภาษาเดียวกับที่ลูกค้าใช้
- ให้ข้อมูลที่เป็นประโยชน์
- ยอมรับเมื่อไม่รู้คำตอบ
PROMPT;
    }
}
