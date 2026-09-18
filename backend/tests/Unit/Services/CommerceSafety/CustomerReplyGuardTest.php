<?php

namespace Tests\Unit\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\CommerceSafety\CartValidation;
use App\Services\CommerceSafety\CustomerReplyGuard;
use App\Services\CommerceSafety\CustomerReplyPolicy;
use App\Services\CommerceSafety\FinancialOutputGuard;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CustomerReplyGuardTest extends TestCase
{
    public function test_shadow_logs_only_ids_and_reason_and_preserves_the_entire_result(): void
    {
        DB::shouldReceive('connection')->never();
        config(['commerce_safety.bots.26.mode' => 'shadow']);
        $bot = (new Bot)->forceFill(['id' => 26]);
        $conversation = (new Conversation)->forceFill(['id' => 12]);
        $result = ['content' => 'ผมเป็น AI @notourshop99', 'order_payload' => ['total' => 199],
            'commerce_safety_cart_validation' => new CartValidation(true, [], [], 19900, false, 'test'),
            'checkout_presentation' => ['action' => 'payment']];
        Log::shouldReceive('warning')->once()->with('Guardrail output sanitizer triggered', [
            'bot_id' => 26, 'conversation_id' => 12, 'reason' => 'ai_admission_th',
        ]);
        Log::shouldReceive('warning')->once()->with('Customer reply policy triggered', [
            'bot_id' => 26, 'conversation_id' => 12, 'reason' => 'contact_handle',
        ]);
        $this->assertSame($result, app(CustomerReplyGuard::class)->generated($bot, $result, $conversation));
    }

    public function test_off_and_bot27_backstops_preserve_all_bytes_and_metadata_without_logs(): void
    {
        Log::shouldReceive('warning')->never();
        $guard = app(CustomerReplyGuard::class);
        $result = ['content' => "ผมเป็น AI @notourshop99\n```php\n", 'order_payload' => ['total' => 199], 'checkout_presentation' => true];
        foreach ([26, 27] as $id) {
            config(["commerce_safety.bots.$id.mode" => $id === 26 ? 'off' : 'enforce']);
            $this->assertSame($result, $guard->generated((new Bot)->forceFill(['id' => $id]), $result));
        }
    }

    public function test_rejections_clear_all_proposal_authority_and_keep_precedence(): void
    {
        config(['commerce_safety.bots.26.mode' => 'enforce']);
        $bot = (new Bot)->forceFill(['id' => 26]);
        $guard = app(CustomerReplyGuard::class);
        foreach (['@notourshop99' => CustomerReplyPolicy::FALLBACK, "```php\n@notourshop99" => OffTopicCircuitBreaker::CANNED_MESSAGE] as $text => $fallback) {
            $result = $guard->generated($bot, ['content' => $text,
                'cart_validation' => ['corrected' => true, 'errors' => ['PRICE_MISMATCH']],
                'commerce_safety_cart_validation' => new CartValidation(true, [], [], 19900, false, 'test'),
                'order_payload' => ['total' => 199], 'checkout_presentation' => ['action' => 'payment']]);
            $this->assertSame(['content' => $fallback, 'order_payload' => null], $result);
        }
        $this->assertSame(FinancialOutputGuard::DENIAL, $guard->text($bot, FinancialOutputGuard::DENIAL));
        config(['commerce_safety.bots.26.allow_truthful_ai_identity' => false]);
        $this->assertSame(OffTopicCircuitBreaker::CANNED_MESSAGE, $guard->text($bot, 'ผมเป็น AI'));
    }

    public function test_legacy_sanitizer_rejections_clear_proposals_even_when_contact_policy_is_not_enforced(): void
    {
        $guard = app(CustomerReplyGuard::class);
        foreach ([26, 27] as $id) {
            foreach (['off', 'shadow', 'enforce', 'hold'] as $mode) {
                config(["commerce_safety.bots.$id.mode" => $mode]);
                $result = $guard->generated((new Bot)->forceFill(['id' => $id]), [
                    'content' => "```php\n@notourshop99",
                    'order_payload' => ['total' => 199],
                    'commerce_safety_cart_validation' => new CartValidation(true, [], [], 19900, false, 'test'),
                    'cart_validation' => ['corrected' => true, 'errors' => ['PRICE_MISMATCH']],
                    'checkout_presentation' => ['action' => 'payment'],
                ], legacySanitizer: true);

                $this->assertSame(['content' => OffTopicCircuitBreaker::CANNED_MESSAGE, 'order_payload' => null], $result);
            }
        }
    }
}
