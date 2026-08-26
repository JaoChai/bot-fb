<?php
/**
 * Characterization pin for ProcessLINEWebhook vision helpers (Task 3) — POST-EXTRACTION half.
 *
 * Runs the same canned scenario as the pre-extraction pin but reads the values
 * from App\Services\Webhook\Channels\LINE\VisionHandler instead of the job.
 * Usage: php artisan tinker --execute="require '/app/tests/pins/vision-pin-post.php';"
 */

namespace Tests;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ConversationContextService;
use App\Services\FlowPluginService;
use App\Services\ModelCapabilityService;
use App\Services\MultipleBubblesService;
use App\Services\OpenRouterService;
use App\Services\PaymentFlexService;
use App\Services\Webhook\Channels\LINE\VisionHandler;

function pin_vision_after()
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create([
        'status' => 'active',
        'name' => 'PinBot',
        'primary_chat_model' => 'google/gemini-3.5-flash',
        'fallback_chat_model' => null,
        'system_prompt' => 'You are a helpful assistant for the pin bot.',
    ]);

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

    include __DIR__.'/../fixtures/line-image-event.php';

    // Build the handler with the SAME real services the job resolved via app().
    // (No Mockery — the pin must exercise the real code path.)
    $handler = new VisionHandler(
        bot: $bot,
        openRouterService: app(OpenRouterService::class),
        modelCapability: app(ModelCapabilityService::class),
        conversationContext: app(ConversationContextService::class),
        bubblesService: app(MultipleBubblesService::class),
        paymentFlexService: app(PaymentFlexService::class),
        flowPluginService: app(FlowPluginService::class),
    );

    $ref = new \ReflectionClass($handler);
    $call = function (string $method, array $args) use ($handler, $ref) {
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($handler, ...$args);
    };

    $out = [];

    try {
        $out['vision_model'] = $call('getVisionModel', []);
    } catch (\Throwable $e) {
        $out['vision_model'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }
    try {
        $out['system_prompt'] = $call('buildVisionSystemPrompt', []);
    } catch (\Throwable $e) {
        $out['system_prompt'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }
    try {
        $out['history'] = $call('getVisionConversationHistory', [$conversation]);
    } catch (\Throwable $e) {
        $out['history'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }
    try {
        $out['image_prompt_pending'] = $call('getImageAnalysisPrompt', [$out['history']]);
    } catch (\Throwable $e) {
        $out['image_prompt_pending'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }
    try {
        $out['image_prompt_default'] = $call('getImageAnalysisPrompt', [[]]);
    } catch (\Throwable $e) {
        $out['image_prompt_default'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }
    try {
        $out['detect_pending_order_history'] = $call('detectPendingOrder', [$out['history']]);
        $out['detect_pending_order_empty'] = $call('detectPendingOrder', [[]]);
    } catch (\Throwable $e) {
        $out['detect_pending_order_history'] = 'EXCEPTION: '.get_class($e).': '.$e->getMessage();
    }
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

pin_vision_after();
