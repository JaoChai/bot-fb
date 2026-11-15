<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Rules\PlainOutageText;
use App\Services\FollowUp\FollowUpCapabilities;
use App\Support\OutageMessageText;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BotSettingController extends Controller
{
    /** ช่องข้อความ outage — ค่าว่าง (whitespace ยูนิโคด) normalize เป็น null ก่อน validate */
    private const OUTAGE_TEXT_FIELDS = [
        'outage_recovery_message_th',
        'outage_recovery_message_en',
        'outage_down_message',
    ];

    /**
     * Get bot settings.
     *
     * @OA\Get(
     *     path="/api/bots/{bot}/settings",
     *     summary="Get bot settings",
     *     description="Returns all configuration settings for a bot. Creates default settings if none exist.",
     *     operationId="getBotSettings",
     *     tags={"Bot Settings"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="data", ref="#/components/schemas/BotSettings")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Bot not found")
     * )
     */
    public function show(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        // Get or create settings for this bot
        $settings = $bot->settings ?? $this->createDefaultSettings($bot);

        return response()->json([
            'data' => $this->settingsData($settings),
        ]);
    }

    /**
     * Update bot settings.
     *
     * @OA\Put(
     *     path="/api/bots/{bot}/settings",
     *     summary="Update bot settings",
     *     description="Updates configuration settings for a bot including usage limits, HITL, response hours, and more.",
     *     operationId="updateBotSettings",
     *     tags={"Bot Settings"},
     *     security={{"bearerAuth":{}}},
     *
     *     @OA\Parameter(
     *         name="bot",
     *         in="path",
     *         description="Bot ID",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="daily_message_limit", type="integer", minimum=0, maximum=100000, example=1000),
     *             @OA\Property(property="per_user_limit", type="integer", minimum=0, maximum=10000, example=100),
     *             @OA\Property(property="rate_limit_per_minute", type="integer", minimum=1, maximum=1000, example=20),
     *             @OA\Property(property="max_tokens_per_response", type="integer", minimum=100, maximum=32000, example=2000),
     *             @OA\Property(property="hitl_enabled", type="boolean", description="Enable Human-in-the-Loop"),
     *             @OA\Property(property="hitl_triggers", type="array", @OA\Items(type="string"), description="Keywords to trigger HITL"),
     *             @OA\Property(property="response_hours_enabled", type="boolean"),
     *             @OA\Property(property="response_hours", type="object", description="Business hours per day"),
     *             @OA\Property(property="response_hours_timezone", type="string", example="Asia/Bangkok"),
     *             @OA\Property(property="offline_message", type="string", maxLength=1000),
     *             @OA\Property(property="welcome_message", type="string", maxLength=2000),
     *             @OA\Property(property="fallback_message", type="string", maxLength=1000),
     *             @OA\Property(property="typing_indicator", type="boolean"),
     *             @OA\Property(property="typing_delay_ms", type="integer", minimum=0, maximum=5000),
     *             @OA\Property(property="blocked_keywords", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="analytics_enabled", type="boolean"),
     *             @OA\Property(property="save_conversations", type="boolean"),
     *             @OA\Property(property="language", type="string", enum={"th", "en", "zh", "ja", "ko"}),
     *             @OA\Property(property="response_style", type="string", enum={"professional", "casual", "friendly", "formal"}),
     *             @OA\Property(property="multiple_bubbles_enabled", type="boolean"),
     *             @OA\Property(property="smart_aggregation_enabled", type="boolean"),
     *             @OA\Property(property="auto_assignment_enabled", type="boolean"),
     *             @OA\Property(property="auto_assignment_mode", type="string", enum={"round_robin", "load_balanced"})
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Settings updated successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Settings updated successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/BotSettings")
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Bot not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        // ค่าว่างทุกแบบ (รวม whitespace ยูนิโคด) = null ก่อน validate — ช่องว่าง 501 ตัวอักษร
        // จึงเป็น "ใช้ค่าเริ่มต้น" ไม่ใช่ข้อความยาวเกิน (Lead D1: sanitizer รันก่อนหน้านี้แล้วเสมอ)
        foreach (self::OUTAGE_TEXT_FIELDS as $field) {
            $value = $request->input($field);
            if (array_key_exists($field, $request->all()) && is_string($value) && OutageMessageText::isBlank($value)) {
                $request->merge([$field => null]);
            }
        }

        // คีย์ภายในของระบบตามลูกค้า (D64): body หรือ query ที่มีคีย์ใด ๆ ต่อไปนี้ → 422 เสมอ
        // (ทั้งค่าจริงและ null); Laravel `prohibited` ยอม null จึงต้องเช็คเอง
        foreach (['follow_up_started_at', 'follow_up_activation_message_id', 'follow_up_activated_at', 'follow_up_capabilities'] as $prohibitedKey) {
            if (array_key_exists($prohibitedKey, $request->all())) {
                throw ValidationException::withMessages([
                    $prohibitedKey => ['The '.$prohibitedKey.' field must not be sent.'],
                ]);
            }
        }

        $validated = $request->validate([
            // Usage limits
            'daily_message_limit' => 'integer|min:0|max:100000',
            'per_user_limit' => 'integer|min:0|max:10000',
            'rate_limit_per_minute' => 'integer|min:1|max:1000',
            'max_tokens_per_response' => 'integer|min:100|max:32000',

            // HITL settings
            'hitl_enabled' => 'boolean',
            'hitl_triggers' => 'nullable|array',
            'hitl_triggers.*' => 'string|max:100',

            // Response hours (multiple slots per day)
            // Format: { "mon": [{"start":"09:00","end":"18:00"}], ... }
            'response_hours_enabled' => 'boolean',
            'response_hours' => 'nullable|array',
            'response_hours.*' => 'nullable|array',
            'response_hours.*.*.start' => 'date_format:H:i',
            'response_hours.*.*.end' => 'date_format:H:i',
            'response_hours_timezone' => 'nullable|string|timezone',
            'offline_message' => 'nullable|string|max:1000',

            // Auto-responses
            'welcome_message' => 'nullable|string|max:2000',
            'fallback_message' => 'nullable|string|max:1000',
            'rate_limit_bot_message' => 'nullable|string|max:500',
            'rate_limit_user_message' => 'nullable|string|max:500',
            'typing_indicator' => 'boolean',
            'typing_delay_ms' => 'integer|min:0|max:5000',

            // Content moderation
            'blocked_keywords' => 'nullable|array',
            'blocked_keywords.*' => 'string|max:100',

            // Analytics
            'analytics_enabled' => 'boolean',
            'save_conversations' => 'boolean',

            // Language and style
            'language' => 'string|in:th,en,zh,ja,ko',
            'response_style' => 'string|in:professional,casual,friendly,formal',

            // Conversation management
            'auto_archive_days' => 'nullable|integer|min:1|max:365',

            // Multiple bubbles settings
            'multiple_bubbles_enabled' => 'boolean',
            'multiple_bubbles_min' => 'integer|min:1|max:5',
            'multiple_bubbles_max' => 'integer|min:1|max:5|gte:multiple_bubbles_min',
            'multiple_bubbles_delimiter' => 'string|max:10',
            'wait_multiple_bubbles_enabled' => 'boolean',
            'wait_multiple_bubbles_ms' => 'integer|min:500|max:20000',

            // Smart aggregation settings
            'smart_aggregation_enabled' => 'boolean',
            'smart_min_wait_ms' => 'sometimes|integer|min:300|max:3000',
            'smart_max_wait_ms' => ['sometimes', 'integer', 'min:1000', 'max:10000'],
            'smart_early_trigger_enabled' => 'boolean',
            'smart_per_user_learning_enabled' => 'boolean',

            // Reply sticker settings
            'reply_sticker_enabled' => 'boolean',
            'reply_sticker_message' => 'nullable|string|max:500',
            'reply_sticker_mode' => 'string|in:static,ai',
            'reply_sticker_ai_prompt' => 'nullable|string|max:1000',

            // Auto-assignment settings
            'auto_assignment_enabled' => 'boolean',
            'auto_assignment_mode' => 'string|in:round_robin,load_balanced',

            // Slip verification settings
            'slip_verification_enabled' => 'boolean',
            'slip_receiver_account' => 'nullable|string|max:50',
            'slip_amount_tolerance' => 'numeric|min:0|max:10000',
            'slip_success_message' => 'nullable|string|max:1000',
            'slip_fail_message' => 'nullable|string|max:1000',

            // Outage settings ("เมื่อบอทล่ม") — ค่าว่างเก็บ null เพื่อ inherit ค่าเริ่มต้นโค้ด
            'outage_recovery_enabled' => 'sometimes|boolean',
            'outage_recovery_message_th' => ['sometimes', 'nullable', 'string', 'max:500', new PlainOutageText],
            'outage_recovery_message_en' => ['sometimes', 'nullable', 'string', 'max:500', new PlainOutageText],
            'outage_down_message' => ['sometimes', 'nullable', 'string', 'max:500', new PlainOutageText],

            // Customer follow-up switch (B5a, D62-D64): JSON true/false เท่านั้น (ไม่ยอม
            // 1/0/"1"/"true") — กฎเชิงพฤติกรรม (supported/mode) ตรวจใน transaction ด้านล่าง
            'follow_up_enabled' => [static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('The follow_up_enabled field must be JSON true or false.');
                }
            }],
            'follow_up_mode' => ['string', 'in:approve,automatic'],
            // snapshot + capabilities เป็นคอลัมน์ภายในที่ระบบตั้งเองตอนเปิดครั้งแรก —
            // client ห้ามส่งทุกรูปร่าง; Laravel `prohibited` ยอมค่า null (validateRequired
            // ล้มเหลวบน null) จึงเช็ค array_key_exists เองก่อน validate ด้านล่าง
        ]);

        // Validate smart_max_wait_ms >= smart_min_wait_ms
        if (isset($validated['smart_max_wait_ms'], $validated['smart_min_wait_ms'])) {
            if ($validated['smart_max_wait_ms'] < $validated['smart_min_wait_ms']) {
                return response()->json([
                    'message' => 'The smart max wait must be greater than or equal to smart min wait.',
                    'errors' => [
                        'smart_max_wait_ms' => ['The smart max wait must be greater than or equal to smart min wait.'],
                    ],
                ], 422);
            }
        }

        // Customer follow-up switch (B5a, D62-D66) — เขียนครั้งเดียวใน transaction เดียว:
        // ล็อกแถวก่อนอ่านค่าเดิม, กฎเชิงพฤติกรรม 422 ก่อนเขียนอะไร (all-or-nothing),
        // snapshot เขียนครั้งเดียวกัน whereNull กันชน, request ที่ไม่มีคีย์ follow-up
        // ทำงานเหมือนเดิมทุกอย่าง
        $settings = DB::transaction(function () use ($bot, $validated, $request): BotSetting {
            // SQLite: lock เป็น no-op / PostgreSQL: FOR UPDATE จริง
            $settings = BotSetting::query()->where('bot_id', $bot->id)->lockForUpdate()->first();

            $storedEnabled = (bool) ($settings?->follow_up_enabled ?? false);
            $storedMode = (string) ($settings?->follow_up_mode ?? 'approve');
            $startedAt = $settings?->follow_up_started_at;

            $requestedEnabled = $validated['follow_up_enabled'] ?? null;
            $requestedMode = $validated['follow_up_mode'] ?? null;

            // "Now" = เวลากรุงเทพฯ ไร้โซน เก็บตรง ๆ ไม่แปลง UTC
            $nowBangkok = Carbon::now('Asia/Bangkok')->format('Y-m-d H:i:s');
            $newMode = $storedMode;
            $snapshot = null;

            // false → true: ผ่านเมื่อ supported เท่านั้น (true → true เป็น no-op ไม่เช็ค)
            if ($requestedEnabled === true && ! $storedEnabled) {
                $capabilities = FollowUpCapabilities::forBot($bot, CarbonImmutable::now());
                if (! $capabilities['supported']) {
                    throw ValidationException::withMessages([
                        'follow_up_enabled' => [$capabilities['blocked_reason'] ?? 'unsupported'],
                    ]);
                }

                if ($startedAt === null) {
                    // snapshot เขียนครั้งเดียวตอนเปิดครั้งแรกสำเร็จ; high-water อ่านใน transaction เดียวกัน
                    $snapshot = [
                        'follow_up_started_at' => $nowBangkok,
                        'follow_up_activated_at' => $nowBangkok,
                        'follow_up_activation_message_id' => (int) (DB::table('messages')->max('id') ?? 0),
                    ];
                }
            }

            // automatic ยอมรับเมื่อ (เมื่อ request นี้ถูก apply แล้ว) สวิตช์เปิด และ automatic_allowed;
            // automatic ที่เก็บไว้แล้วซ้ำ = no-op ยอมรับเสมอ (ไม่เช็ค capabilities ใหม่)
            if ($requestedMode === 'automatic' && ! ($requestedMode === $storedMode && $requestedEnabled !== false)) {
                $effectiveEnabled = $requestedEnabled ?? $storedEnabled;
                if (! $effectiveEnabled) {
                    throw ValidationException::withMessages([
                        'follow_up_mode' => ['switch_off'],
                    ]);
                }

                $capabilities = FollowUpCapabilities::forBot($bot, CarbonImmutable::now());
                if (! $capabilities['automatic_allowed']) {
                    throw ValidationException::withMessages([
                        'follow_up_mode' => ['automatic_not_allowed'],
                    ]);
                }
            }

            if ($requestedMode !== null && $requestedMode !== $storedMode) {
                $newMode = $requestedMode;
            }

            // ปิดสวิตช์รีเซ็ตโหมดเป็น approve เสมอ (ไม่มี dormant automatic)
            if ($requestedEnabled === false) {
                $newMode = 'approve';
            }

            // ผ่านทุกเงื่อนไขแล้วจึงสร้างแถว — request ที่ถูกปฏิเสธต้องไม่เกิดแถวใหม่
            if ($settings === null) {
                $settings = $this->createDefaultSettings($bot);
            }

            // ช่องอื่นบันทึกเหมือนเดิม; คีย์ follow-up เขียนผ่าน query builder เท่านั้น
            $settings->update(collect($validated)->except(['follow_up_enabled', 'follow_up_mode'])->all());

            if ($snapshot !== null) {
                // กันชน (D62): เขียนได้เมื่อ snapshot ยังว่างเท่านั้น — ถ้านักเขียนคนอื่น
                // แทรกค่าไปก่อนหลังจากที่เราอ่านแถวแล้ว ค่าของเขาชนะ request ไม่ทับ
                DB::table('bot_settings')
                    ->where('bot_id', $bot->id)
                    ->whereNull('follow_up_started_at')
                    ->update($snapshot);
            }

            $switchWrite = [];
            if ($requestedEnabled !== null && (bool) $requestedEnabled !== $storedEnabled) {
                $switchWrite['follow_up_enabled'] = $requestedEnabled;
            }
            if ($newMode !== $storedMode) {
                $switchWrite['follow_up_mode'] = $newMode;
            }
            if ($switchWrite !== []) {
                $switchWrite['updated_at'] = $nowBangkok;
                DB::table('bot_settings')->where('bot_id', $bot->id)->update($switchWrite);
            }

            // ล็อกหนึ่งบรรทัดต่อการพลิกสวิตช์ (เปิดหรือปิดก็ตาม) — ไม่มี token/chat id/allowlist
            if ($requestedEnabled !== null && (bool) $requestedEnabled !== $storedEnabled) {
                Log::info('Customer follow-up switch changed.', [
                    'bot_id' => $bot->id,
                    'user_id' => (int) $request->user()->id,
                    'follow_up_enabled' => ['old' => $storedEnabled, 'new' => (bool) $requestedEnabled],
                    'follow_up_mode' => ['old' => $storedMode, 'new' => $newMode],
                ]);
            }

            return $settings;
        });

        return response()->json([
            'message' => 'Settings updated successfully',
            'data' => $this->settingsData($settings->fresh()),
        ]);
    }

    /**
     * รูปทรงข้อมูล settings ร่วมของ GET และ PUT/PATCH — เพิ่ม outage_defaults (response-only,
     * ห้ามเก็บลง DB) ต่อท้ายคอลัมน์จริงของแถว และสี่คีย์ของสวิตช์ตามลูกค้า (B5a)
     * ที่ต้องอยู่ในทุก response: enabled (JSON bool), mode, started_at (ISO 8601
     * ของ instant เดียวกับเวลากรุงเทพฯ ที่เก็บ) และ capabilities จาก FollowUpCapabilities;
     * คอลัมน์ภายในสองตัวถูกซ่อนที่ $hidden ของโมเดล
     *
     * @return array<string, mixed>
     */
    private function settingsData(BotSetting $settings): array
    {
        $startedAt = $settings->follow_up_started_at;

        return array_merge($settings->toArray(), [
            'outage_defaults' => OutageMessageText::defaults(),
            'follow_up_enabled' => (bool) $settings->follow_up_enabled,
            'follow_up_mode' => (string) ($settings->follow_up_mode ?? 'approve'),
            'follow_up_started_at' => $startedAt === null
                ? null
                : CarbonImmutable::parse($startedAt, 'Asia/Bangkok')->toISOString(),
            'follow_up_capabilities' => FollowUpCapabilities::forBot($settings->bot, CarbonImmutable::now()),
        ]);
    }

    /**
     * Create default settings for a bot.
     */
    private function createDefaultSettings(Bot $bot): BotSetting
    {
        $settings = BotSetting::create([
            'bot_id' => $bot->id,
            'daily_message_limit' => 1000,
            'per_user_limit' => 100,
            'rate_limit_per_minute' => 20,
            'max_tokens_per_response' => 2000,
            'hitl_enabled' => false,
            'response_hours_enabled' => false,
            'typing_indicator' => true,
            'typing_delay_ms' => 1000,
            'analytics_enabled' => true,
            'save_conversations' => true,
            'language' => 'th',
            'response_style' => 'professional',
            // Outage settings — เขียนชัดเจนให้ response ครบโดยไม่ต้องอ่านซ้ำจาก DB
            'outage_recovery_enabled' => true,
            'outage_recovery_message_th' => null,
            'outage_recovery_message_en' => null,
            'outage_down_message' => null,
            // Note: multiple_bubbles and auto_assignment fields use database defaults
            // follow_up_* (B5a) ใช้ default ของ DB — refresh เพื่อให้ response แรกมีคีย์ครบ
        ]);

        $settings->refresh();

        return $settings;
    }
}
