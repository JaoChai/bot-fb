# Bot 26 Image Classification (`image_kind`) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unblock T16/T17/T30 by extending the bot-26 slip-image classifier's contract from a binary `is_slip` boolean to a three-way `image_kind` (`bank_app_slip | camera_photo_of_screen | other`), fail-closed, bot-26-scoped, while keeping every other bot's behavior byte-for-byte identical.

**Architecture:** `LineWebhookResponseService::classifySlipImage()` already runs a single vision call that returns JSON `{is_slip, reply}` and is used both as `SlipVerificationService::verify()`'s `?bool` fail-safe closure and as a cached "draft reply" for the non-slip branch. We extend the JSON schema/instruction/`decodeSlipCheck()` to add an `image_kind` enum **only when `(int) $ctx->bot->getKey() === 26`** (the codebase's existing bot-26-scoping idiom, see `CustomerReplyPolicy::mode()`). `image_kind` drives three outcomes inside `classifySlipImage()` without touching `SlipVerificationService`'s public contract (`?bool` closure) at all: `bank_app_slip` → behaves exactly like today's `is_slip=true`; `camera_photo_of_screen` → returns `false` (not a valid slip) but stashes a **fixed** Thai template (not model prose) into `$ctx->metadata['slip_vision_draft']`; `other` → behaves like today's `is_slip=false` (model's free-text `reply` used, unchanged); any unrecognized/invalid `image_kind` value is treated as a parse failure (`null`), which already fails closed to the existing "unreadable slip / staff alert" branch. Non-bot-26 bots keep the exact current 2-key schema/instruction, so their prompt payload to the vision model is unchanged.

**Tech Stack:** Laravel 12 (PHP), PHPUnit, OpenRouter vision API (`chatWithVision` with `json_schema` structured output when the model supports it), existing `SlipVerificationService` payment pipeline.

**Spec:** `docs/testing/bot26-v28-evaluation.md` (Images section) and the task brief in this conversation. Ground truth for current contract: `app/Services/LineWebhook/LineWebhookResponseService.php:799-896` (`classifySlipImage`/`decodeSlipCheck`), `app/Services/Payment/SlipVerificationService.php:364-582` (`verify`/`apiUnavailable`/`visionSaysNotSlip`).

## Global Constraints

- Local only. No production access, no deploys, no `railway run`, no prod DB/Redis env for any phpunit/artisan invocation.
- Bot-26 scoped: gate every new instruction/schema/template on `(int) $ctx->bot->getKey() === 26`. Zero behavior change for any other bot — verified by leaving all non-bot-26 existing tests untouched and green.
- Fail closed: an unrecognized/ambiguous `image_kind` (or classifier transport failure) must never be treated as a valid slip and must never auto-confirm payment; it routes to the existing "unreadable slip" staff-alert branch (`SlipVerificationResult(isSlip: true, passed: false, failReason: 'unreadable')`).
- TDD: every new behavior gets a RED test (fails against current `main`) before the GREEN implementation.
- Live model calls only via the OpenRouter credential recipe in the task brief, cost ceiling $0.05, never printed/logged, in-process only, synthetic images only (never real customer slips).
- Full suite baseline to reproduce after changes: **2362 tests, 0 failures, 62 skipped** (measured on `main` post-merge, `76326026`).
- Commit messages end `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`. PR body ends with the Claude Code attribution line from the system reminder.

---

## Current contract (read before coding — do not re-derive this)

- `app/Services/LineWebhook/LineWebhookResponseService.php:799` `classifySlipImage(WebhookContext $ctx, string $imageUrl, array $history): ?bool` — single vision call, JSON schema `slip_image_check` = `{is_slip: bool, reply: string}` (lines 819-833). Returns `true`/`false`/`null` (null = transport failure or malformed JSON, logged at :849-857 with `reason: 'malformed_classification'`, safe diagnostics only — never raw content).
- `app/Services/LineWebhook/LineWebhookResponseService.php:888` `decodeSlipCheck(string $content): ?array` — parses via `LlmJson::extractObject` + `json_decode`, requires `is_bool($data['is_slip'])` and `is_string($data['reply'])`, else `null`.
- `app/Services/LineWebhook/LineWebhookResponseService.php:698` — `classifySlipImage` is invoked as `fn(): ?bool => $this->classifySlipImage(...)` and passed into `SlipVerificationService::verify()`'s `?\Closure $isSlipCheck` parameter. **This closure's `?bool` contract is a shared payment-domain seam — do not change its type.**
- `app/Services/Payment/SlipVerificationService.php:556` `visionSaysNotSlip()`: `true` only when `$isSlipCheck() === false`. `true`/`null` both mean "treat as slip" (fail-safe toward the payment side).
- `app/Services/Payment/SlipVerificationService.php:389-401` (400 branch) and `:567-582` (`apiUnavailable`): when a pending order exists in history and vision does not say "not slip", records `SlipVerificationResult(isSlip:true, passed:false, failReason:'unreadable')` and the caller alerts admin (`LineWebhookResponseService.php:701-707`, `:744-747`). This is the existing "unreadable-slip/staff-alert branch" the task requires unknown kinds to route into.
- `app/Services/LineWebhook/LineWebhookResponseService.php:863-869` — when `is_slip===false && reply!==''`, the reply is cached into `$ctx->metadata['slip_vision_draft']` so `generateImageResponse` (line ~424-447) reuses it instead of calling vision a second time.
- Bot-26 scoping idiom already established: `app/Services/CommerceSafety/CustomerReplyPolicy.php:14` — `if ((int) $bot->getKey() !== 26) { return 'off'; }`. This plan reuses the same literal `26` check, independent of `commerce_safety.bots.26.mode` (which defaults to `off` in tests/most environments and must not gate this).
- Existing template constants for reference/pattern: `SLIP_SUCCESS_TEMPLATE`, `SLIP_FAIL_TEMPLATE`, `SLIP_PENDING_TEMPLATE`, `IMAGE_UNAVAILABLE_TEMPLATE` at `LineWebhookResponseService.php:40-47`.
- Fixtures today: `T16` (`is_slip:true`, unreadable/staff-alert), `T17` (`is_slip:false` + canned reply, `classification_gate_blocked:true`), `T30` (`is_slip:false` + canned reply, Meta-screenshot/tech-support). Eval test wiring: `tests/Feature/PromptEval/Bot26PromptEvaluationTest.php:585-618` (`test_application_image_handler_boundary`), gate assertion at `:592-595`, skip-list at `:59`, manifest reason at `image_classification.reason`.

## New contract

- `classifySlipImage()` return type **stays `?bool`** (backward compatible, unchanged closure contract for `SlipVerificationService`).
- New private const `IMAGE_KINDS = ['bank_app_slip', 'camera_photo_of_screen', 'other']`.
- New public const `CAMERA_PHOTO_SLIP_TEMPLATE = 'รบกวนส่งรูปสลิปต้นฉบับจากแอปธนาคารโดยตรงครับ ไม่รับรูปถ่ายหน้าจอจากกล้องครับ'` (matches current T17 fixture text verbatim — a **fixed** template, not model prose, so wording never depends on model creativity).
- When `(int) $ctx->bot->getKey() === 26`: vision instruction + `json_schema` (name `slip_image_check_v2`) request `{image_kind: enum[...], is_slip: bool, reply: string}`, `required: [image_kind, is_slip, reply]`. Instruction explains: `bank_app_slip` = native bank/финance app screenshot; `camera_photo_of_screen` = a photo taken with a camera of a screen/device showing a slip (shop policy: rejected, only the bank app's own screenshot is accepted); `other` = unrelated image. `reply` stays empty for `bank_app_slip`/`camera_photo_of_screen`, free text for `other`.
- `decodeSlipCheck()` additionally accepts optional `image_kind`; if present it must be one of `IMAGE_KINDS` (string) or the whole response is malformed → `null` (fail closed, same log path/`reason: 'malformed_classification'` as today — no new log reason, minimal diff).
- `classifySlipImage()` derives the canonical slip decision from `image_kind` when present (never trusts a possibly-contradictory raw `is_slip` from the model once `image_kind` is known):
  - `bank_app_slip` → returns `true` (identical downstream behavior to today's `is_slip:true`).
  - `camera_photo_of_screen` → stashes `CAMERA_PHOTO_SLIP_TEMPLATE` into `$ctx->metadata['slip_vision_draft']`, returns `false`.
  - `other` → identical to today's `is_slip:false` path (model's own `reply` cached as draft when non-empty).
  - `image_kind` absent (legacy 2-key JSON, e.g. any test/caller still sending the old shape) → identical to today's behavior, unchanged.
- Non-bot-26 bots: instruction/schema/name (`slip_image_check`) and `decodeSlipCheck` success path are **byte-identical** to current `main`.

---

## Task 1: Fixed camera-photo template + `IMAGE_KINDS` constant (no behavior change yet)

**Files:**
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php:40-47` (constants block)
- Test: `backend/tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php`

**Interfaces:**
- Produces: `LineWebhookResponseService::CAMERA_PHOTO_SLIP_TEMPLATE` (public string const), `LineWebhookResponseService::IMAGE_KINDS` is **private** — do not reference it from tests; tests assert behavior, not the const.

- [ ] **Step 1: Write the failing test** — assert the public constant exists with the exact fixture wording:

```php
public function test_camera_photo_slip_template_matches_shop_policy_wording(): void
{
    $this->assertSame(
        'รบกวนส่งรูปสลิปต้นฉบับจากแอปธนาคารโดยตรงครับ ไม่รับรูปถ่ายหน้าจอจากกล้องครับ',
        LineWebhookResponseService::CAMERA_PHOTO_SLIP_TEMPLATE
    );
}
```

- [ ] **Step 2: Run to verify RED**

Run: `cd backend && php -d memory_limit=1G vendor/bin/phpunit --no-coverage --filter test_camera_photo_slip_template_matches_shop_policy_wording tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php`
Expected: FAIL — `Undefined constant` (const doesn't exist yet).

- [ ] **Step 3: Add the constants** in `LineWebhookResponseService.php` right after `IMAGE_UNAVAILABLE_TEMPLATE` (line 47):

```php
    // Bot 26 shop rule (prompt v28): only the bank app's own slip screenshot is accepted.
    // Fixed wording — camera-photo rejections must never depend on model creativity.
    public const CAMERA_PHOTO_SLIP_TEMPLATE = 'รบกวนส่งรูปสลิปต้นฉบับจากแอปธนาคารโดยตรงครับ ไม่รับรูปถ่ายหน้าจอจากกล้องครับ';

    // Bot-26-only classifier kinds. Any other value (or absence, for legacy 2-key JSON)
    // is handled by classifySlipImage()/decodeSlipCheck() — see there for the fail-closed rule.
    private const IMAGE_KINDS = ['bank_app_slip', 'camera_photo_of_screen', 'other'];
```

- [ ] **Step 4: Run to verify GREEN**

Run: same command as Step 2.
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd /private/tmp/bot26-image
git add backend/app/Services/LineWebhook/LineWebhookResponseService.php backend/tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php
git commit -m "$(cat <<'EOF'
feat(bot26): add camera-photo-slip template and image_kind constants

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Extend `decodeSlipCheck()` to accept optional `image_kind` (fail closed on invalid enum)

**Files:**
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php:888-896` (`decodeSlipCheck`)
- Test: `backend/tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php` (via a thin reflection-free approach is not available since the method is private — this task's tests live in Task 4's pipeline tests instead; skip a standalone unit test here and fold verification into Task 4 to avoid reflection hacks, matching the codebase's existing testing style for this private method).

This task's code change has no independently-observable behavior until Task 3 wires `image_kind` into the outbound schema, so it is implemented together with Task 3 rather than committed alone (folding avoids an artificial intermediate commit with untestable code, per Task Right-Sizing). Proceed directly to Task 3.

---

## Task 3: Bot-26-gated `image_kind` schema/instruction + decode + branch logic in `classifySlipImage()`

**Files:**
- Modify: `backend/app/Services/LineWebhook/LineWebhookResponseService.php:799-896` (`classifySlipImage`, `decodeSlipCheck`)
- Test: `backend/tests/Feature/SlipVerificationPipelineTest.php` (new bot-26-scoped tests)

**Interfaces:**
- Consumes: `LineWebhookResponseService::CAMERA_PHOTO_SLIP_TEMPLATE`, `IMAGE_KINDS` (Task 1).
- Produces: `classifySlipImage(): ?bool` unchanged signature; internally now bot-26-aware. `decodeSlipCheck(string $content): ?array{is_slip: bool, reply: string, image_kind: ?string}`.

### RED tests first (all four go into `SlipVerificationPipelineTest.php`, using the existing `makeBotAndConversation(['id' => 26])` + `enableTelegramAlert()` + `makeContext()` helpers already in that file)

- [ ] **Step 1: Write the four failing tests**

Add after `test_classifier_failure_keeps_unreadable_fail_safe` (around line 498):

```php
    public function test_bot26_classifier_requests_image_kind_and_bank_app_slip_flows_to_unreadable_alert(): void
    {
        $this->makeBotAndConversation(['id' => 26]);
        $this->enableTelegramAlert();
        $this->partialMock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
            $mock->shouldReceive('supportsStructuredOutput')->with('google/gemini-3.5-flash')->andReturn(true);
        });

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"image_kind": "bank_app_slip", "is_slip": true, "reply": ""}']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        // New request shape: the schema must offer the model a camera-photo kind to pick from.
        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'openrouter.ai')) {
                return false;
            }
            $schema = $req->data()['response_format']['json_schema']['schema'] ?? [];

            return ($schema['properties']['image_kind']['enum'] ?? null) === ['bank_app_slip', 'camera_photo_of_screen', 'other']
                && in_array('image_kind', $schema['required'] ?? [], true);
        });

        // bank_app_slip must still flow through the existing verification/unreadable-alert path.
        $this->assertStringContainsString('ขอตรวจสอบยอดสักครู่', $ctx->response->payload);
        $this->assertSame('unreadable', $ctx->metadata['bot_message']->metadata['slip_status']);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'unreadable']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
    }

    public function test_bot26_camera_photo_of_screen_gets_fixed_reply_and_no_effects(): void
    {
        $this->makeBotAndConversation(['id' => 26]);
        $this->enableTelegramAlert();

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"image_kind": "camera_photo_of_screen", "is_slip": false, "reply": ""}']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        $this->assertStringContainsString('ต้นฉบับ', $ctx->response->payload);
        $this->assertStringContainsString('แอปธนาคาร', $ctx->response->payload);
        $this->assertStringContainsString('กล้อง', $ctx->response->payload);
        $this->assertDatabaseCount('slip_verifications', 0);
        $this->assertDatabaseCount('verified_payment_events', 0);
        $this->assertDatabaseCount('orders', 0);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        // Single classify call only — no second vision call, no drift from the fixed template.
        $this->assertCount(1, Http::recorded(fn ($req) => str_contains($req->url(), 'openrouter.ai')));
    }

    public function test_bot26_unrecognized_image_kind_fails_closed_to_staff_alert(): void
    {
        $this->makeBotAndConversation(['id' => 26]);
        $this->enableTelegramAlert();
        // Force manual-JSON mode (no schema enum enforcement) so an out-of-contract
        // image_kind value can actually reach decodeSlipCheck() for this RED test.
        $this->partialMock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
            $mock->shouldReceive('supportsStructuredOutput')->andReturn(false);
        });

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['content' => '{"image_kind": "photocopy", "is_slip": false, "reply": "แนบเอกสาร"}']]],
                'model' => 'google/gemini-3.5-flash',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
            ]),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        // Fail closed: unknown kind must NOT be treated as a valid slip, must NOT auto-confirm,
        // and must NOT just silently reject — it routes to the existing unreadable/staff-alert branch.
        $this->assertStringContainsString('ขอตรวจสอบยอดสักครู่', $ctx->response->payload);
        $this->assertSame('unreadable', $ctx->metadata['bot_message']->metadata['slip_status']);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'unreadable']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        $this->assertDatabaseCount('verified_payment_events', 0);
    }

    public function test_bot26_classifier_transport_failure_fails_closed_under_new_schema(): void
    {
        $this->makeBotAndConversation(['id' => 26]);
        $this->enableTelegramAlert();
        $this->partialMock(ModelCapabilityService::class, function ($mock) {
            $mock->shouldReceive('supportsVision')->andReturn(true);
            $mock->shouldReceive('supportsStructuredOutput')->with('google/gemini-3.5-flash')->andReturn(true);
        });

        Http::fake([
            'api.easyslip.com/*' => Http::response(['success' => false, 'error' => ['code' => 'INVALID_IMAGE_TYPE', 'message' => 'invalid image type']], 400),
            'api.line.me/*' => Http::response(['ok' => true]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'openrouter.ai/*' => Http::response([], 500),
        ]);

        $ctx = $this->makeContext();
        app(LineWebhookResponseService::class)->generate($ctx);

        // The new bot-26 schema must still have been requested even though the call failed.
        Http::assertSent(function ($req) {
            if (! str_contains($req->url(), 'openrouter.ai')) {
                return false;
            }
            $schema = $req->data()['response_format']['json_schema']['schema'] ?? [];

            return in_array('image_kind', $schema['required'] ?? [], true);
        });
        $this->assertStringContainsString('ขอตรวจสอบยอดสักครู่', $ctx->response->payload);
        $this->assertSame('unreadable', $ctx->metadata['bot_message']->metadata['slip_status']);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'unreadable']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.telegram.org'));
        $this->assertDatabaseCount('verified_payment_events', 0);
    }
```

**Note bot ID collision:** `makeBotAndConversation` is called a second time per test (once in `setUp()` for the default bot, once inside the test for id 26). Confirm `Bot::factory()->create(['id' => 26, ...])` does not collide with the `setUp()` bot — it doesn't, because `RefreshDatabase` gives a fresh auto-increment sequence per test and `setUp()`'s bot never takes id 26 (existing `test_malformed_classification_logs_only_safe_diagnostics` already proves this pattern works).

- [ ] **Step 2: Run all four to verify RED**

Run: `cd backend && php -d memory_limit=1G vendor/bin/phpunit --no-coverage --filter "test_bot26_classifier_requests_image_kind_and_bank_app_slip_flows_to_unreadable_alert|test_bot26_camera_photo_of_screen_gets_fixed_reply_and_no_effects|test_bot26_unrecognized_image_kind_fails_closed_to_staff_alert|test_bot26_classifier_transport_failure_fails_closed_under_new_schema" tests/Feature/SlipVerificationPipelineTest.php`
Expected: FAIL — the `image_kind` schema-shape assertions fail on all four (property absent), and the camera-photo test additionally fails its `ต้นฉบับ`/`แอปธนาคาร`/`กล้อง` assertions (falls through to a second vision call that echoes the raw JSON back as prose) and its single-call-count assertion; the unrecognized-kind test fails because current code ignores `image_kind` and uses `reply:"แนบเอกสาร"` as a draft instead of alerting.

- [ ] **Step 3: Implement.** Replace `classifySlipImage()` and `decodeSlipCheck()` (lines 799-896) with:

```php
    /**
     * ตัดสิน+ร่างคำตอบในการเรียกครั้งเดียว (single-call structured output) — ใช้ตอน EasySlip
     * อ่านรูปไม่ได้ (400) เพื่อแยก "สลิปเบลอ" ออกจาก "รูปทั่วไป" (เช่น screenshot หน้าจออื่นๆ)
     *
     * บอท 26 เท่านั้น (กฎร้าน v28: รับเฉพาะสกรีนช็อตจากแอปธนาคารโดยตรง ไม่รับรูปถ่ายหน้าจอ)
     * ได้ image_kind เพิ่มจาก is_slip เดิม — บอทอื่นยัง schema/คำสั่งเดิมทุกตัวอักษร
     *
     * คำตัดสิน (is_slip) กับคำตอบลูกค้า (reply) มาจากการมองรูปครั้งเดียวกัน จึงขัดแย้งกันเองไม่ได้
     * ถ้าไม่ใช่สลิป reply จะถูกเก็บไว้ใน metadata ให้ generateImageResponse ใช้เลยโดยไม่เรียก vision ซ้ำ
     * คืน null เมื่อตอบไม่ได้/เรียกไม่สำเร็จ/image_kind ไม่รู้จัก → ฝั่ง verify() จะถือเป็นสลิป (fail-safe ไปทางตรวจมือ)
     */
    private function classifySlipImage(WebhookContext $ctx, string $imageUrl, array $history): ?bool
    {
        try {
            $model = $this->getVisionModel($ctx);
            if (! $model) {
                return null;
            }

            $apiKey = $ctx->bot->user?->settings?->getOpenRouterApiKey()
                ?? config('services.openrouter.api_key');

            $isBot26 = (int) $ctx->bot->getKey() === 26;

            if ($isBot26) {
                $instruction = "ลูกค้าส่งรูปมา (แนบมากับข้อความนี้) ให้ตอบเป็น JSON เท่านั้น ห้ามมีข้อความอื่นนอก JSON รูปแบบ:\n"
                    ."{\"image_kind\": \"bank_app_slip|camera_photo_of_screen|other\", \"is_slip\": true/false, \"reply\": \"...\"}\n"
                    ."- image_kind: bank_app_slip = สกรีนช็อตสลิปโอนเงินที่แคปจากแอปธนาคาร/แอปการเงินโดยตรง (ไม่ใช่รูปถ่าย); "
                    ."camera_photo_of_screen = รูปที่ถ่ายด้วยกล้องจากหน้าจอ/เอกสารที่แสดงสลิป (เห็นขอบจอ แสงสะท้อน มุมกล้อง) — ร้านไม่รับ ต้องขอสกรีนช็อตจากแอปธนาคารเท่านั้น; "
                    ."other = รูปอื่นที่ไม่เกี่ยวกับสลิปเลย\n"
                    ."- is_slip: true เฉพาะเมื่อ image_kind เป็น bank_app_slip เท่านั้น, false เมื่อเป็น camera_photo_of_screen หรือ other\n"
                    .'- reply: เมื่อ image_kind เป็น other ให้เขียนข้อความตอบลูกค้าตามบริบทบทสนทนา; เมื่อเป็น bank_app_slip หรือ camera_photo_of_screen ให้ใส่สตริงว่าง ""';

                $schema = [
                    'type' => 'object',
                    'properties' => [
                        'image_kind' => [
                            'type' => 'string',
                            'enum' => self::IMAGE_KINDS,
                            'description' => 'ประเภทของรูปที่ลูกค้าส่งมา',
                        ],
                        'is_slip' => [
                            'type' => 'boolean',
                            'description' => 'true เฉพาะเมื่อ image_kind เป็น bank_app_slip',
                        ],
                        'reply' => [
                            'type' => 'string',
                            'description' => 'ข้อความตอบลูกค้าตามบริบทบทสนทนา เมื่อ image_kind เป็น other; สตริงว่างเมื่อเป็นอย่างอื่น',
                        ],
                    ],
                    'required' => ['image_kind', 'is_slip', 'reply'],
                    'additionalProperties' => false,
                ];
                $schemaName = 'slip_image_check_v2';
            } else {
                $instruction = "ลูกค้าส่งรูปมา (แนบมากับข้อความนี้) ให้ตอบเป็น JSON เท่านั้น ห้ามมีข้อความอื่นนอก JSON รูปแบบ:\n"
                    ."{\"is_slip\": true/false, \"reply\": \"...\"}\n"
                    ."- is_slip: true เมื่อรูปเป็นสลิปโอนเงิน/หลักฐานการชำระเงินจากธนาคารหรือแอปการเงิน, false เมื่อเป็นรูปอื่น\n"
                    .'- reply: เมื่อ is_slip เป็น false ให้เขียนข้อความตอบลูกค้าตามบริบทบทสนทนา; เมื่อ is_slip เป็น true ให้ใส่สตริงว่าง ""';

                $schema = [
                    'type' => 'object',
                    'properties' => [
                        'is_slip' => [
                            'type' => 'boolean',
                            'description' => 'true เมื่อรูปเป็นสลิปโอนเงิน/หลักฐานการชำระเงินจากธนาคารหรือแอปการเงิน',
                        ],
                        'reply' => [
                            'type' => 'string',
                            'description' => 'ข้อความตอบลูกค้าตามบริบทบทสนทนา เมื่อ is_slip เป็น false; สตริงว่างเมื่อ is_slip เป็น true',
                        ],
                    ],
                    'required' => ['is_slip', 'reply'],
                    'additionalProperties' => false,
                ];
                $schemaName = 'slip_image_check';
            }

            $messages = $this->buildVisionChatMessages($ctx, array_slice($history, -5), $instruction);

            // chatWithVision ใส่ json_schema ให้เฉพาะ model ที่รองรับ structured_outputs —
            // ตัวอื่นพึ่งคำสั่ง JSON ใน prompt แล้ว parse เอง (decodeSlipCheck รองรับ JSON ห่อข้อความ/code fence)
            $result = $this->openRouterService->chatWithVision(
                messages: $messages,
                imageUrls: [$imageUrl],
                model: $model,
                // JSON ต้อง parse ได้เสถียร — ใช้ temperature ต่ำคงที่ ไม่ใช้ค่าแชทของบอท
                temperature: 0.3,
                maxTokens: $ctx->bot->llm_max_tokens ?? 1024,
                apiKeyOverride: $apiKey,
                fallbackModelOverride: $ctx->bot->fallback_chat_model,
                responseFormat: ['type' => 'json_schema', 'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema]],
            );

            $decoded = $this->decodeSlipCheck($result['content'] ?? '');

            Log::info('Slip image classification', [
                'bot_id' => $ctx->bot->id,
                'conversation_id' => $ctx->conversation?->id,
                ...($decoded === null ? [
                    'content_length' => mb_strlen($result['content'] ?? ''),
                    'content_hash' => hash('sha256', $result['content'] ?? ''),
                    'reason' => 'malformed_classification',
                ] : ['is_slip' => $decoded['is_slip'], 'image_kind' => $decoded['image_kind']]),
            ]);

            if ($decoded === null) {
                return null;
            }

            $imageKind = $decoded['image_kind'];

            if ($imageKind === 'camera_photo_of_screen') {
                $ctx->metadata['slip_vision_draft'] = [
                    'content' => self::CAMERA_PHOTO_SLIP_TEMPLATE,
                    'model' => $result['model'] ?? $model,
                    'usage' => $result['usage'] ?? [],
                ];

                return false;
            }

            if ($imageKind === 'bank_app_slip') {
                return true;
            }

            // image_kind === 'other', or absent (legacy 2-key JSON from non-bot-26 schema) —
            // identical to the pre-image_kind behavior.
            if ($decoded['is_slip'] === false && $decoded['reply'] !== '') {
                $ctx->metadata['slip_vision_draft'] = [
                    'content' => $decoded['reply'],
                    'model' => $result['model'] ?? $model,
                    'usage' => $result['usage'] ?? [],
                ];
            }

            return $decoded['is_slip'];
        } catch (\Throwable $e) {
            Log::warning('Slip image classification failed, treating as slip (fail-safe)', [
                'bot_id' => $ctx->bot->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * แกะผล JSON ของ classifySlipImage — รองรับทั้ง JSON ล้วน (structured output)
     * และ JSON ที่ห่อด้วยข้อความ/code fence (model ที่ไม่รองรับ) คืน null เมื่อ parse/validate ไม่ผ่าน
     *
     * image_kind เป็น optional (บอทที่ไม่ใช่ 26 ไม่ส่งคีย์นี้มา) — ถ้ามีต้องอยู่ใน IMAGE_KINDS
     * เท่านั้น ไม่งั้นถือว่า parse ไม่ผ่าน (fail closed แบบเดียวกับ JSON พัง)
     *
     * @return array{is_slip: bool, reply: string, image_kind: ?string}|null
     */
    private function decodeSlipCheck(string $content): ?array
    {
        $data = json_decode(LlmJson::extractObject($content), true);
        if (! is_array($data) || ! is_bool($data['is_slip'] ?? null) || ! is_string($data['reply'] ?? null)) {
            return null;
        }

        $imageKind = $data['image_kind'] ?? null;
        if ($imageKind !== null && (! is_string($imageKind) || ! in_array($imageKind, self::IMAGE_KINDS, true))) {
            return null;
        }

        return ['is_slip' => $data['is_slip'], 'reply' => trim($data['reply']), 'image_kind' => $imageKind];
    }
```

- [ ] **Step 4: Run the four new tests to verify GREEN**

Run: same filter as Step 2.
Expected: PASS, all four.

- [ ] **Step 5: Run the full existing regression set for this file + the malformed-logging test + the structured-output test to confirm zero regressions**

Run: `cd backend && php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Feature/SlipVerificationPipelineTest.php`
Expected: all pass (existing tests use the default, non-26 bot and the untouched 2-key schema/instruction, except `test_malformed_classification_logs_only_safe_diagnostics` which is bot-26 but sends non-JSON content — unaffected).

- [ ] **Step 6: Commit**

```bash
cd /private/tmp/bot26-image
git add backend/app/Services/LineWebhook/LineWebhookResponseService.php backend/tests/Feature/SlipVerificationPipelineTest.php
git commit -m "$(cat <<'EOF'
feat(bot26): extend slip classifier with fail-closed image_kind contract

Bot-26-gated only; every other bot keeps the exact binary is_slip
schema/instruction. camera_photo_of_screen gets a fixed Thai template
instead of trusting model prose; any unrecognized/invalid image_kind
value fails closed into the existing unreadable-slip/staff-alert branch.

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Regression check — other bots and existing bot-26 tests unaffected

**Files:** none changed; verification only.

- [ ] **Step 1:** Run the full set of files that touch this code path:

```bash
cd backend
php -d memory_limit=1G vendor/bin/phpunit --no-coverage \
  tests/Feature/SlipVerificationPipelineTest.php \
  tests/Feature/CommerceSafety/Bot26EndToEndTest.php \
  tests/Feature/PipelineImageRoutingTest.php \
  tests/Feature/SlipVerificationServiceTest.php \
  tests/Feature/SlipVerificationSchemaTest.php \
  tests/Feature/SlipRetryServiceTest.php \
  tests/Feature/ManualPaymentConfirmTest.php \
  tests/Feature/BotSlipSettingsApiTest.php \
  tests/Feature/CommerceSafety/CheckoutSettlementTest.php \
  tests/Feature/CommerceSafety/HeldPaymentReceiptEffectTest.php \
  tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php
```

Expected: all pass, including `Bot26EndToEndTest::test_failed_slips_and_image_classifications_never_authorize_money` with its `T16/T17/T30`-labeled legacy 2-key JSON data provider (must keep passing unmodified — proves the legacy/absent-`image_kind` path is untouched).

- [ ] **Step 2:** If anything fails, stop and fix before proceeding — do not touch files outside this list without re-reading why.

- [ ] **Step 3:** No commit (verification-only task).

---

## Task 5: Update T16/T17/T30 fixtures + eval test to exercise `image_kind`

**Files:**
- Modify: `backend/tests/Fixtures/PromptEval/bot26-v28/T16.json`
- Modify: `backend/tests/Fixtures/PromptEval/bot26-v28/T17.json`
- Modify: `backend/tests/Fixtures/PromptEval/bot26-v28/T30.json`
- Modify: `backend/tests/Feature/PromptEval/Bot26PromptEvaluationTest.php:57-59,590-599`

**Interfaces:**
- Consumes: Task 3's `image_kind` contract.

- [ ] **Step 1: Write the failing test change.** Edit `test_application_image_handler_boundary` (`Bot26PromptEvaluationTest.php:585-618`): delete the T17 gate assertion block (lines 590-595) and change the `fakeTransport` call (line 599) to include `image_kind`:

```php
    #[DataProvider('imageCases')]
    public function test_application_image_handler_boundary(array $case): void
    {
        $this->persistApplication($case);
        $this->enableSlip();
        // Synthetic payment prose triggers EasySlip's unreadable-image classifier branch.
        // It is deliberately NOT checkout or payment authority.
        $this->conversation->messages()->create(['sender' => 'bot', 'type' => 'text', 'content' => "สรุปรายการ\n1. Page (199 x 1) = 199 บาท\nรวมยอดโอน: 199 บาท\n223-3-24880-3"]);
        $this->fakeTransport(json_encode([
            'image_kind' => $case['image']['image_kind'],
            'is_slip' => $case['image']['is_slip'],
            'reply' => $case['image']['reply'],
        ], JSON_UNESCAPED_UNICODE));
        $ctx = $this->handler($case['message'], 'image');
```

(Rest of the method body is unchanged — leave lines 601-618 exactly as-is.)

Also update the class docblock (line 49) and the `RAW_IMAGE_SKIP_IDS` comment (lines 57-59) to drop the "BLOCKED camera-photo classification gate" wording — replace with a note that all three now exercise the `image_kind` contract via canned classifier output (still not live inference).

- [ ] **Step 2: Update the three fixture JSON files.** `T16.json` — add `"image_kind": "bank_app_slip"` to the `image` object (keep `is_slip: true`, `reply: ""`, and the rest unchanged):

```json
  "image": {
    "image_kind": "bank_app_slip",
    "is_slip": true,
    "reply": "",
    "expected_contains": [
      "สลิป",
      "ตรวจสอบ"
    ],
    "boundary": "EasySlip unreadable + bank_app_slip image_kind classification; unreadable/staff-alert branch proven with a canned classifier output."
  },
```

`T17.json` — add `"image_kind": "camera_photo_of_screen"`, remove `classification_gate_blocked`/`classification_gate_blocked_reason`, update `boundary`:

```json
  "image": {
    "image_kind": "camera_photo_of_screen",
    "is_slip": false,
    "reply": "รบกวนส่งรูปสลิปต้นฉบับจากแอปธนาคารโดยตรงครับ ไม่รับรูปถ่ายหน้าจอจากกล้องครับ",
    "expected_contains": [
      "ต้นฉบับ",
      "แอปธนาคาร",
      "กล้อง"
    ],
    "boundary": "camera_photo_of_screen is now a distinct classifier outcome (bot-26-scoped) with a fixed reply template, proven end-to-end via a canned classifier output. The real vision model's ability to visually distinguish a camera photo of a screen from a native app screenshot on an actual photograph is NOT proven here — see docs/testing/bot26-v28-evaluation.md."
  },
```

(Remove the `classification_gate_blocked` and `classification_gate_blocked_reason` top-level keys entirely from `T17.json`.)

`T30.json` — add `"image_kind": "other"`:

```json
  "image": {
    "image_kind": "other",
    "is_slip": false,
    "reply": "ติดต่อ Technical Support ที่ https://lin.ee/h5wYpIf หรือ LINE @743ddeqy ครับ",
    "expected_contains": [
      "Technical Support",
      "https://lin.ee/h5wYpIf",
      "@743ddeqy"
    ],
    "boundary": "Synthetic Meta UI screenshot; fake other classification, no diagnosis."
  },
```

- [ ] **Step 3: Run to verify RED before the fixture edits are complete / before manifest sync** (expect failure only on the offline inventory hash check, since fixture bytes changed — this is the intentional signal to do Task 6 next):

Run: `cd backend && php -d memory_limit=1G vendor/bin/phpunit --no-coverage --filter "test_application_image_handler_boundary|test_offline_artifact_and_fixture_inventory" tests/Feature/PromptEval/Bot26PromptEvaluationTest.php`
Expected: `test_application_image_handler_boundary` (T16/T17/T30) PASS; `test_offline_artifact_and_fixture_inventory` FAIL (`sha256`/`chars`/`bytes` mismatch for T16/T17/T30 against the stale manifest).

- [ ] **Step 4: Commit is deferred to Task 6** (manifest must be updated in the same logical change so the repo never has a broken offline-inventory test on `HEAD`).

---

## Task 6: Recompute and update `manifest.json`, update `docs/testing/bot26-v28-evaluation.md`

**Files:**
- Modify: `backend/tests/Fixtures/PromptEval/bot26-v28/manifest.json`
- Modify: `docs/testing/bot26-v28-evaluation.md`

- [ ] **Step 1:** Recompute exact bytes/chars/sha256 for the three edited fixture files and update `manifest.json`'s `fixtures[]` entries for `T16`/`T17`/`T30`, and `layers.image_classification`:

```bash
cd /private/tmp/bot26-image/backend
php -r '
foreach (["T16","T17","T30"] as $id) {
    $bytes = file_get_contents("tests/Fixtures/PromptEval/bot26-v28/{$id}.json");
    echo "$id chars=".mb_strlen($bytes)." bytes=".strlen($bytes)." sha256=".hash("sha256", $bytes)."\n";
}
'
```

Update each of the three `fixtures[]` entries' `chars`/`bytes`/`sha256` with these exact values (do not hand-compute — use the script output). Update `layers.image_classification`:

```json
    "image_classification": {
      "status": "proven_via_canned_classifier_output",
      "raw_skip_cases": ["T16", "T17", "T30"],
      "classification_gate_blocked_cases": [],
      "reason": "All three cases now exercise the bot-26-scoped image_kind contract (bank_app_slip/camera_photo_of_screen/other) end-to-end with a canned classifier JSON response. Not proven: whether the real vision model correctly assigns image_kind on an actual photograph (camera photo of a phone screen vs. a native bank-app screenshot); see docs/testing/bot26-v28-evaluation.md for what the live spot-check (if run) covers."
    }
```

- [ ] **Step 2:** Run the offline inventory test to verify GREEN:

Run: `cd backend && php -d memory_limit=1G vendor/bin/phpunit --no-coverage --filter test_offline_artifact_and_fixture_inventory tests/Feature/PromptEval/Bot26PromptEvaluationTest.php`
Expected: PASS.

- [ ] **Step 3:** Update `docs/testing/bot26-v28-evaluation.md`:
  - Line 11: replace "Image classification is **blocked**; T17 does not establish camera-photo recognition." with a statement that the binary `is_slip` contract has been extended to a bot-26-scoped `image_kind` (`bank_app_slip | camera_photo_of_screen | other`), proven end-to-end (unit + application layers) with canned/synthetic classifier output and — if Task 8's live check ran — with a synthetic-image spot check; state explicitly that real-photo visual discrimination by the model (camera photo vs. native screenshot) is not proven by the fixture/application layers alone.
  - Section "Images" (lines 105-116): rewrite to describe the new `image_kind` contract, that T16/T17/T30 all now send `image_kind` in their canned classifier payload, and what remains unproven (live/production visual accuracy of the classifier on real photographs).
  - Do not touch any other section (text cases, raw mode, reproduction commands) — those are out of scope for this change.

- [ ] **Step 4: Commit (fixtures + eval test + manifest + doc together — this is one logical, always-green change)**

```bash
cd /private/tmp/bot26-image
git add backend/tests/Fixtures/PromptEval/bot26-v28/T16.json backend/tests/Fixtures/PromptEval/bot26-v28/T17.json backend/tests/Fixtures/PromptEval/bot26-v28/T30.json backend/tests/Fixtures/PromptEval/bot26-v28/manifest.json backend/tests/Feature/PromptEval/Bot26PromptEvaluationTest.php docs/testing/bot26-v28-evaluation.md
git commit -m "$(cat <<'EOF'
docs(bot26): unblock T16/T17/T30 image classification gate

T16/T17/T30 now exercise the bot-26 image_kind contract
(bank_app_slip/camera_photo_of_screen/other) end-to-end instead of the
binary is_slip stand-in. classification_gate_blocked is removed; the
manifest and evaluation doc state exactly what is proven (fixture +
application layers, canned classifier output) and what remains
unproven (real-photo visual accuracy of the live vision model).

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: Live model spot-check (optional, only if it can run safely)

**Files:** none committed from this task except possibly an addendum to the evaluation doc if it runs.

- [ ] **Step 1:** Confirm the OpenRouter credential can be extracted per the task recipe, in-process, without ever being printed/logged/written:

```bash
codex mcp get openrouter --json | python3 -c "import json,sys;t=json.load(sys.stdin)['transport'];assert t['url']=='https://mcp.openrouter.ai/mcp';v=t['http_headers']['Authorization'];assert v.startswith('Bearer ');print('OK: token present, length', len(v)-7)"
```

If this fails (no Codex MCP config available, wrong URL, missing header), **stop this task**, do not fabricate a key, and record in the PR/report that the live check was skipped and why.

- [ ] **Step 2:** Generate 3 synthetic PNGs locally (no real customer data): a slip-looking layout (bank app style: logo block, "โอนเงินสำเร็จ", amount, account number, green checkmark), a photo-of-screen-looking layout (the same content rendered inside a visible phone/monitor bezel with a slight rotation + vignette to simulate a camera photo), and an unrelated UI screenshot (e.g., a fake Meta Business Suite-style panel). Simple PHP GD or Python PIL script writing to the scratchpad directory is sufficient — no external asset downloads.

- [ ] **Step 3:** Write a small standalone PHP script (not a persisted test file) that resolves `OpenRouterService` from a booted Laravel app (or calls the HTTP API directly) with the extracted key injected only as an in-memory env var for that process, sends each of the 3 images through the same `slip_image_check_v2` schema/instruction built in Task 3 (extract the instruction/schema literally — do not hand-retype it, copy-paste from the implemented method to avoid drift), and prints only: image label, returned `image_kind`, `is_slip`, request cost (from OpenRouter usage in the response). Track cumulative cost; abort before exceeding $0.05.

- [ ] **Step 4:** Run it, capture the 3 results (image label → image_kind/is_slip/cost) in your final report. If the model misclassifies, report that honestly — it is evidence about the current prompt/schema's real-world accuracy, not a reason to alter the fixtures (fixtures test the code contract, not model judgment).

- [ ] **Step 5:** If this step ran, append a short dated note to `docs/testing/bot26-v28-evaluation.md`'s Images section with the 3 results and note this is a 3-sample spot check, not a statistically meaningful evaluation. Commit this doc addendum alone if it's the only change:

```bash
cd /private/tmp/bot26-image
git add docs/testing/bot26-v28-evaluation.md
git commit -m "$(cat <<'EOF'
docs(bot26): record 3-sample live image_kind spot check

Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 6:** If Step 1 fails or the classifier cannot be exercised safely (no real LINE image URL available, credential unavailable, etc.), explicitly state this in the final report and rely on the fake-transport tests from Tasks 3 and 5 as the evidence — do not skip this reporting step.

---

## Task 8: Full verification, commit, push, PR

**Files:** none (verification/process only, beyond what Tasks 1-6 already committed).

- [ ] **Step 1:** Targeted tests one more time (belt and suspenders):

```bash
cd /private/tmp/bot26-image/backend
php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Feature/SlipVerificationPipelineTest.php tests/Feature/CommerceSafety/Bot26EndToEndTest.php tests/Feature/PromptEval/Bot26PromptEvaluationTest.php tests/Unit/Services/LineWebhook/LineWebhookResponseServiceTest.php
```

Expected: all pass.

- [ ] **Step 2:** Full suite from `backend/`:

```bash
cd /private/tmp/bot26-image/backend
php -d memory_limit=1G ./vendor/bin/phpunit --no-coverage
```

Expected/compare against baseline: **2362 tests, 0 failures, 62 skipped** — record the exact printed summary line in the report (it may differ slightly, e.g. +new test count; 0 failures is the hard requirement).

- [ ] **Step 3:** Style and diff hygiene:

```bash
cd /private/tmp/bot26-image/backend
vendor/bin/pint --test
git diff --check
```

Fix any reported issues, re-run, then commit the fix (`style: pint` / whitespace fix) if anything changed.

- [ ] **Step 4:** Push and open PR:

```bash
cd /private/tmp/bot26-image
git push -u origin feat/bot26-image-classification
gh pr create --title "feat(bot26): extend image classifier with fail-closed image_kind" --body "$(cat <<'EOF'
## สรุป
...(RED/GREEN evidence per behavior, fixture/doc changes, live-model results or why skipped, full-suite line, explicit unproven list)...

## Test plan
...

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 5:** Do **not** merge. Poll CI status (`gh pr checks <number>` or `gh pr view <number>`) and report the PR URL + CI status in the final report, along with the explicit "what remains unproven" list (real-photo visual accuracy of the live model; any other bot with `slip_verification_enabled` that isn't bot 26 — none currently configured, but the code no longer assumes that; live check results or why it was skipped).

---

## Self-review notes (completed during planning, not a task)

- Spec coverage: task items 1 (contract discovery) → satisfied by "Current contract" section above; 2 (extend contract, fail closed, bot-26 scoped) → Task 3; 3 (TDD for all 4 behaviors) → Task 3's four RED tests; 4 (fixtures/eval test/manifest/doc) → Tasks 5-6; 5 (live check) → Task 7; 6 (verify) → Task 8 Steps 1-3; 7 (commit/push/PR, no merge) → Task 8 Steps 4-5.
- Placeholder scan: no TBD/"add error handling"/"similar to Task N" — all code blocks are complete and copy-pasteable.
- Type consistency: `decodeSlipCheck` return shape `array{is_slip: bool, reply: string, image_kind: ?string}` used consistently in Task 3's implementation; `classifySlipImage(): ?bool` signature unchanged everywhere it's referenced (Tasks 3-4).
