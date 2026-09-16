<?php

namespace Tests\Unit\Services\CommerceSafety;

use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\CommerceSafety\FinancialOutputGuard;
use App\Services\CommerceSafety\SafetyScope;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext;
use App\Services\Payment\PaymentMessageDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class FinancialOutputGuardTest extends TestCase
{
    #[Test]
    #[DataProvider('outputs')]
    public function checkout_output_guards_preserve_scope_and_enforce_action_token_policy(string $text, bool $financial): void
    {
        Http::preventStrayRequests();
        DB::shouldReceive('connection')->never();
        $bot = new Bot;
        $bot->setRelation('settings', new BotSetting(['slip_receiver_account' => '987-6-54321-0']));
        $conversation = new Conversation;
        $ctx = new WebhookContext($bot, []);
        $ctx->conversation = $conversation;
        $message = new Message(['content' => $text]);

        foreach (['enforce', 'hold', 'off', 'shadow'] as $mode) {
            $scope = Mockery::mock(SafetyScope::class);
            $scope->shouldReceive('mode')->with($bot)->andReturn($mode);
            $this->app->instance(SafetyScope::class, $scope);
            $ai = app(AIService::class);
            $validation = (new ReflectionMethod($ai, 'inspectScopedProposal'))->invoke($ai, $bot, $conversation, $text);
            $parser = app(PaymentMessageDetector::class);
            $parsed = $parser->parsePaymentData($text) ?? $parser->parseConfirmData($text);
            if (($financial || $parsed !== null) && $mode !== 'off') {
                $this->assertNotNull($validation, $text);
                $this->assertFalse($validation->valid);
            } else {
                $this->assertNull($validation, $text);
            }

            $response = app(LineWebhookResponseService::class);
            $guarded = (new ReflectionMethod($response, 'guardGeneratedPaymentText'))->invoke($response, $ctx, $text);
            $job = new ProcessAggregatedMessages($bot, $conversation, 'detector-test', 'test-user');
            $outcome = (new ReflectionMethod($job, 'checkoutProposal'))->invoke($job, ['content' => $text]);
            if ($financial && in_array($mode, ['enforce', 'hold'], true)) {
                $this->assertSame(FinancialOutputGuard::DENIAL, $guarded);
                $this->assertSame('manual_hold', $outcome?->action);
            } else {
                $this->assertSame($text, $guarded);
                $this->assertNull($outcome);
            }

            // The synchronous caller owns scope; its proposal helper is unscoped.
            if (in_array($mode, ['enforce', 'hold'], true)) {
                $syncOutcome = (new ReflectionMethod($response, 'checkoutProposal'))->invoke($response, $ctx, $message);
                $this->assertSame($financial ? 'manual_hold' : null, $syncOutcome?->action, $text);
            }
        }
    }

    public static function outputs(): array
    {
        $outputs = [];
        foreach (FinancialOutputDetectorTest::contextualPaymentDirectives() as $name => [$text]) {
            $outputs[$name] = [$text, true];
        }
        foreach (FinancialOutputDetectorTest::financialDiscussionPolicy() as $name => [$text, $denied]) {
            $outputs[$name] = [$text, $denied];
        }

        return $outputs;
    }
}
