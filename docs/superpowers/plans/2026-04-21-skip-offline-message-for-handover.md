# Skip Offline Message for Handover Conversations — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** When a conversation is in handover mode (Bot Active = OFF), don't send the offline/auto-reply message during closed hours — just save the user's message silently.

**Architecture:** Add an early `is_handover` check in `ProcessLINEWebhook` before sending the offline message. For text messages, query the existing conversation before the response hours check. For non-text messages, use the already-loaded conversation object. Both paths skip the offline message when handover is active.

**Tech Stack:** Laravel 12 / PHP 8.4 / PostgreSQL

---

## Problem Analysis

Two code paths in `ProcessLINEWebhook.php` send offline messages without checking handover:

1. **Text messages (line 211-217):** `checkResponseHours()` runs and sends offline message *before* the transaction that loads the conversation and checks `is_handover`.

2. **Non-text messages (line 849-872):** `checkResponseHours()` runs *after* saving the message but sends offline message without checking `conversation->is_handover`.

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `backend/app/Jobs/ProcessLINEWebhook.php` | Modify | Add handover check before offline message in both text and non-text flows |
| `backend/tests/Unit/Jobs/ProcessLINEWebhookOfflineTest.php` | Create | Test that offline message is skipped for handover conversations |

---

### Task 1: Write failing tests for handover + offline message behavior

**Files:**
- Create: `backend/tests/Unit/Jobs/ProcessLINEWebhookOfflineTest.php`

- [ ] **Step 1: Write the test file with two test cases**

```php
<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Services\LINEService;
use App\Services\ResponseHoursService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessLINEWebhookOfflineTest extends TestCase
{
    use RefreshDatabase;

    protected Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->bot = Bot::factory()->create([
            'user_id' => $user->id,
            'channel_type' => 'line',
            'status' => 'active',
        ]);

        BotSetting::factory()->create([
            'bot_id' => $this->bot->id,
            'response_hours_enabled' => true,
            'offline_message' => 'ขณะนี้อยู่นอกเวลาทำการครับ',
            'response_hours' => [], // empty = all days closed
        ]);
    }

    private function makeTextEvent(string $userId = 'U_test_user'): array
    {
        return [
            'type' => 'message',
            'replyToken' => 'test_reply_token',
            'source' => ['type' => 'user', 'userId' => $userId],
            'message' => ['id' => 'msg_' . uniqid(), 'type' => 'text', 'text' => 'สวัสดีครับ'],
            'webhookEventId' => 'evt_' . uniqid(),
            'timestamp' => now()->getTimestampMs(),
        ];
    }

    public function test_sends_offline_message_when_no_handover(): void
    {
        // Active conversation (not handover) — should send offline message
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'U_test_user',
            'channel_type' => 'line',
            'status' => 'active',
            'is_handover' => false,
        ]);

        $lineService = $this->mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->andReturn(true);
        $lineService->shouldReceive('extractUserId')->andReturn('U_test_user');
        $lineService->shouldReceive('extractReplyToken')->andReturn('test_reply_token');
        $lineService->shouldReceive('extractMessage')->andReturn(['id' => 'msg_1', 'text' => 'สวัสดี']);
        $lineService->shouldReceive('extractWebhookEventId')->andReturn('evt_1');
        $lineService->shouldReceive('extractEventTimestamp')->andReturn(now()->getTimestampMs());
        $lineService->shouldReceive('isRedelivery')->andReturn(false);

        // Expect offline message IS sent (replyWithFallback called)
        $lineService->shouldReceive('replyWithFallback')->once();
        $lineService->shouldReceive('generateRetryKey')->andReturn('retry_1');

        $job = new ProcessLINEWebhook($this->bot, $this->makeTextEvent());
        $job->handle(
            $lineService,
            app(\App\Services\AIService::class),
            app(\App\Services\RateLimitService::class),
            app(\App\Services\MessageAggregationService::class),
            app(ResponseHoursService::class),
            app(\App\Services\CircuitBreakerService::class),
        );
    }

    public function test_skips_offline_message_when_handover(): void
    {
        // Handover conversation — should NOT send offline message
        $conversation = Conversation::factory()->create([
            'bot_id' => $this->bot->id,
            'external_customer_id' => 'U_test_user',
            'channel_type' => 'line',
            'status' => 'handover',
            'is_handover' => true,
        ]);

        $lineService = $this->mock(LINEService::class);
        $lineService->shouldReceive('isMessageEvent')->andReturn(true);
        $lineService->shouldReceive('isTextMessage')->andReturn(true);
        $lineService->shouldReceive('extractUserId')->andReturn('U_test_user');
        $lineService->shouldReceive('extractReplyToken')->andReturn('test_reply_token');
        $lineService->shouldReceive('extractMessage')->andReturn(['id' => 'msg_2', 'text' => 'สวัสดี']);
        $lineService->shouldReceive('extractWebhookEventId')->andReturn('evt_2');
        $lineService->shouldReceive('extractEventTimestamp')->andReturn(now()->getTimestampMs());
        $lineService->shouldReceive('isRedelivery')->andReturn(false);

        // Expect offline message is NOT sent
        $lineService->shouldNotReceive('replyWithFallback');
        $lineService->shouldReceive('generateRetryKey')->andReturn('retry_2')->byDefault();

        $job = new ProcessLINEWebhook($this->bot, $this->makeTextEvent());
        $job->handle(
            $lineService,
            app(\App\Services\AIService::class),
            app(\App\Services\RateLimitService::class),
            app(\App\Services\MessageAggregationService::class),
            app(ResponseHoursService::class),
            app(\App\Services\CircuitBreakerService::class),
        );
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=ProcessLINEWebhookOfflineTest`

Expected: `test_skips_offline_message_when_handover` FAILS because `replyWithFallback` is still called (offline message sent despite handover).

---

### Task 2: Fix text message flow — check handover before sending offline message

**Files:**
- Modify: `backend/app/Jobs/ProcessLINEWebhook.php:210-217`

- [ ] **Step 3: Add handover check before response hours in processEvent()**

In `processEvent()`, after rate limit check (line 209) and before response hours check (line 211), add a query to check if existing conversation is in handover mode. If it is, skip the response hours offline message.

Replace lines 211-217:
```php
        // Check response hours before processing
        $responseHoursResult = $responseHoursService->checkResponseHours($this->bot);
        if (! $responseHoursResult['allowed']) {
            $this->handleOutsideResponseHours($lineService, $responseHoursService, $replyToken, $userId);

            return;
        }
```

With:
```php
        // Check response hours before processing
        $responseHoursResult = $responseHoursService->checkResponseHours($this->bot);
        if (! $responseHoursResult['allowed']) {
            // Skip offline message if conversation is in handover mode (bot disabled for this customer)
            $existingConv = Conversation::where('bot_id', $this->bot->id)
                ->where('external_customer_id', $userId)
                ->where('channel_type', 'line')
                ->whereIn('status', ['active', 'handover'])
                ->first();

            if (! $existingConv || ! $existingConv->is_handover) {
                $this->handleOutsideResponseHours($lineService, $responseHoursService, $replyToken, $userId);
            }

            return;
        }
```

**Why this approach:** 
- If no existing conversation → new customer → send offline message (normal behavior)
- If existing conversation is NOT handover → send offline message (normal behavior)
- If existing conversation IS handover → skip offline message (the fix)
- Always `return` to skip AI processing during closed hours regardless

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=ProcessLINEWebhookOfflineTest`

Expected: Both tests PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Unit/Jobs/ProcessLINEWebhookOfflineTest.php backend/app/Jobs/ProcessLINEWebhook.php
git commit -m "fix: skip offline message for handover conversations (text)"
```

---

### Task 3: Fix non-text message flow — check handover before sending offline message

**Files:**
- Modify: `backend/app/Jobs/ProcessLINEWebhook.php:860-872`

- [ ] **Step 6: Add handover check in handleNonTextMessage()**

In `handleNonTextMessage()`, the conversation is already loaded at this point. Add `is_handover` check before sending offline message.

Replace lines 860-872:
```php
        if (! $responseHoursResult['allowed']) {
            Log::info('Non-text message received outside response hours', [
                'bot_id' => $this->bot->id,
                'message_type' => $messageType,
                'status' => $responseHoursResult['status'],
                'current_time' => $responseHoursResult['current_time'] ?? null,
            ]);

            // Send offline message for all non-text message types (including stickers)
            $this->handleOutsideResponseHours($lineService, $responseHoursService, $replyToken, $userId);

            return; // Skip AI response
        }
```

With:
```php
        if (! $responseHoursResult['allowed']) {
            Log::info('Non-text message received outside response hours', [
                'bot_id' => $this->bot->id,
                'message_type' => $messageType,
                'status' => $responseHoursResult['status'],
                'current_time' => $responseHoursResult['current_time'] ?? null,
            ]);

            // Skip offline message if conversation is in handover mode
            if (! $conversation?->is_handover) {
                $this->handleOutsideResponseHours($lineService, $responseHoursService, $replyToken, $userId);
            }

            return; // Skip AI response
        }
```

- [ ] **Step 7: Run full test suite**

Run: `cd backend && php artisan test --filter=ProcessLINEWebhookOfflineTest`

Expected: All tests PASS.

- [ ] **Step 8: Also clean up the debug error_log**

Remove the debug `error_log` call at lines 853-858 that was left from a previous debugging session:

```php
        // DEBUG: Log response hours check for images
        if ($messageType === 'image') {
            error_log('IMAGE DEBUG: Response hours check - bot_id='.$this->bot->id.
                ', allowed='.($responseHoursResult['allowed'] ? 'true' : 'false').
                ', status='.($responseHoursResult['status'] ?? 'N/A').
                ', current_time='.($responseHoursResult['current_time'] ?? 'N/A'));
        }
```

Remove these lines entirely.

- [ ] **Step 9: Run full backend test suite**

Run: `cd backend && php artisan test`

Expected: All tests PASS.

- [ ] **Step 10: Commit**

```bash
git add backend/app/Jobs/ProcessLINEWebhook.php
git commit -m "fix: skip offline message for handover conversations (non-text) + remove debug log"
```

---

### Task 4: Code style check

- [ ] **Step 11: Run Pint**

Run: `cd backend && vendor/bin/pint --test`

If fails, run `vendor/bin/pint` to auto-fix, then commit.

- [ ] **Step 12: Final commit (if pint made changes)**

```bash
git add -A
git commit -m "chore: apply pint code style fixes"
```
