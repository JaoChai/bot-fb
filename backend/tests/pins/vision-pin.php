<?php
/**
 * Characterization pin for ProcessLINEWebhook vision helpers (Task 3).
 *
 * Run BEFORE the extraction to record the exact output of the current
 * implementation for a canned image event. Re-run AFTER the extraction
 * against VisionHandler to prove byte-identical behavior.
 *
 * Usage: php artisan tinker --execute="require '/app/tests/pins/vision-pin.php';"
 */

namespace Tests;

use App\Jobs\ProcessLINEWebhook;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\User;

function pin_vision_baseline()
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create([
        'status' => 'active',
        'name' => 'PinBot',
        'primary_chat_model' => 'google/gemini-3.5-flash',
        'fallback_chat_model' => null,
        'system_prompt' => 'You are a helpful assistant for the pin bot.',
    ]);
    $botSetting = BotSetting::create(['bot_id' => $bot->id]);

    $profile = CustomerProfile::factory()->create([
        'external_id' => 'U_img_user',
        'channel_type' => 'line',
    ]);
    $conversation = Conversation::factory()->create([
        'bot_id' => $bot->id,
        'customer_profile_id' => $profile->id,
        'external_customer_id' => 'U_img_user',
        'channel_type' => 'line',
        'status' => 'active',
        'is_handover' => false,
        'last_message_at' => now(),
    ]);

    // Same pending-order history as PipelineImageRoutingTest::setUp().
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'sender' => 'bot',
        'type' => 'text',
        'content' => "สรุปรายการ\n1. Nolimit BM = 1,500 บาท\nรวมยอดโอน: 1,500 บาท\nโอนเข้าบัญชี 223-3-24880-3",
    ]);

    $event = include __DIR__.'/../fixtures/line-image-event.php';

    $job = new ProcessLINEWebhook($bot, $event);
    $ref = new \ReflectionClass($job);

    $call = function (string $method, array $args) use ($job, $ref) {
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($job, ...$args);
    };

    // Mirror of PipelineImageRoutingTest::imageEvent() message payload.
    $messageData = $event['message'];

    $out = [];

    // 1. getVisionModel (needs ModelCapabilityService → real service may call
    // OpenRouter; run with its real binding — it falls back to cache/heuristic
    // in tests since no API token. We only need the string result.)
    try {
        $out['vision_model'] = $call('getVisionModel', []);
    } catch (\Throwable $e) {
        $out['vision_model'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }

    // 2. buildVisionSystemPrompt
    try {
        $out['system_prompt'] = $call('buildVisionSystemPrompt', []);
    } catch (\Throwable $e) {
        $out['system_prompt'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }

    // 3. getVisionConversationHistory (default limit 5)
    try {
        $out['history'] = $call('getVisionConversationHistory', [$conversation]);
    } catch (\Throwable $e) {
        $out['history'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }

    // 4. getImageAnalysisPrompt with that history (pending order → slip prompt)
    try {
        $out['image_prompt_pending'] = $call('getImageAnalysisPrompt', [$out['history']]);
    } catch (\Throwable $e) {
        $out['image_prompt_pending'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }

    // 5. getImageAnalysisPrompt with empty history (no pending order → default prompt)
    try {
        $out['image_prompt_default'] = $call('getImageAnalysisPrompt', [[]]);
    } catch (\Throwable $e) {
        $out['image_prompt_default'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }

    // 6. detectPendingOrder
    try {
        $out['detect_pending_order_history'] = $call('detectPendingOrder', [$out['history']]);
        $out['detect_pending_order_empty'] = $call('detectPendingOrder', [[]]);
    } catch (\Throwable $e) {
        $out['detect_pending_order_history'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }

    // 7. ORDER_CONTEXT_KEYWORDS constant
    try {
        $out['order_context_keywords'] = $ref->getConstant('ORDER_CONTEXT_KEYWORDS');
    } catch (\Throwable $e) {
        $out['order_context_keywords'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }

    ksort($out);
    echo "=== VISION PIN (".date('Y-m-d H:i:s')." ===)\n";
    foreach ($out as $k => $v) {
        if (is_array($v) || is_object($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        echo "--- $k ---\n$v\n\n";
    }
}

pin_vision_baseline();
