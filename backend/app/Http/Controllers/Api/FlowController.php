<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\OpenRouterException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flow\StoreFlowRequest;
use App\Http\Requests\Flow\UpdateFlowRequest;
use App\Http\Resources\FlowResource;
use App\Models\Bot;
use App\Models\Flow;
use App\Services\OpenRouterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class FlowController extends Controller
{
    /**
     * List all flows for a bot.
     */
    public function index(Request $request, Bot $bot): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);

        $flows = $bot->flows()
            ->with('knowledgeBases:knowledge_bases.id,knowledge_bases.name')
            ->orderByDesc('is_default')  // Base flow always first
            ->latest()
            ->paginate($request->input('per_page', 15));

        return FlowResource::collection($flows);
    }

    /**
     * Create a new flow for a bot.
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
        if ($bot->flows()->count() === 0) {
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

        return response()->json([
            'message' => 'Flow created successfully',
            'data' => new FlowResource($flow->load('knowledgeBases')),
        ], 201);
    }

    /**
     * Get a specific flow.
     */
    public function show(Request $request, Bot $bot, Flow $flow): FlowResource
    {
        $this->authorize('view', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        return new FlowResource($flow->load('knowledgeBases'));
    }

    /**
     * Update a flow.
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

        return response()->json([
            'message' => 'Flow updated successfully',
            'data' => new FlowResource($flow->fresh()->load('knowledgeBases')),
        ]);
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
            return response()->json([
                'message' => 'ไม่สามารถลบ Base Flow ได้ Base Flow เป็น Flow หลักของ Bot หากต้องการลบ กรุณาตั้ง Flow อื่นเป็น Default ก่อน',
            ], 422);
        }

        // Don't allow deleting the only flow
        if ($bot->flows()->count() === 1) {
            return response()->json([
                'message' => 'Cannot delete the only flow. Create another flow first.',
            ], 422);
        }

        $flow->delete();

        return response()->json([
            'message' => 'Flow deleted successfully',
        ]);
    }

    /**
     * Set a flow as the default for a bot.
     */
    public function setDefault(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

        // Unset other defaults
        $bot->flows()->update(['is_default' => false]);

        // Set this flow as default
        $flow->update(['is_default' => true]);
        $bot->update(['default_flow_id' => $flow->id]);

        return response()->json([
            'message' => 'Flow set as default successfully',
            'data' => new FlowResource($flow->fresh()),
        ]);
    }

    /**
     * Duplicate a flow.
     */
    public function duplicate(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->ensureFlowBelongsToBot($flow, $bot);

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

        return response()->json([
            'message' => 'Flow duplicated successfully',
            'data' => new FlowResource($newFlow->load('knowledgeBases')),
        ], 201);
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

        // Get API key from user settings (centralized)
        $user = $bot->user;
        $apiKey = null;
        if ($user && $user->settings && $user->settings->hasOpenRouterKey()) {
            $apiKey = $user->settings->openrouter_api_key;
        }

        if (!$apiKey && !config('services.openrouter.api_key')) {
            return response()->json([
                'success' => false,
                'error' => 'ไม่พบ OpenRouter API Key กรุณาตั้งค่าใน Settings',
                'error_code' => 'NO_API_KEY',
            ], 422);
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
            $result = $openRouter->chat(
                messages: $messages,
                model: $flow->model,
                temperature: $flow->temperature ? (float) $flow->temperature : 0.7,
                maxTokens: $flow->max_tokens ?? 2048,
                useFallback: true,
                apiKeyOverride: $apiKey
            );

            Log::info('Flow test successful', [
                'flow_id' => $flow->id,
                'bot_id' => $bot->id,
                'model' => $result['model'],
                'tokens' => $result['usage']['total_tokens'] ?? 0,
            ]);

            return response()->json([
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

            return response()->json([
                'success' => false,
                'error' => $errorMessage,
                'error_code' => $e->isAuthError() ? 'AUTH_ERROR' : ($e->isRateLimited() ? 'RATE_LIMITED' : 'API_ERROR'),
            ], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500);
        } catch (\Exception $e) {
            Log::error('Flow test unexpected error', [
                'flow_id' => $flow->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'เกิดข้อผิดพลาดที่ไม่คาดคิด',
                'error_code' => 'UNEXPECTED_ERROR',
            ], 500);
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

        return response()->json([
            'data' => $templates,
        ]);
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
