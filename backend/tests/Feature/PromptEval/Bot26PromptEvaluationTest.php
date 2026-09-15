<?php

namespace Tests\Feature\PromptEval;

use App\Jobs\ProcessLINEWebhook;
use App\Jobs\ReserveAccountStock;
use App\Jobs\SendDeliveryCard;
use App\Models\Bot;
use App\Models\CheckoutSession;
use App\Models\Conversation;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\KnowledgeBase;
use App\Models\Message;
use App\Models\PaymentEffect;
use App\Models\ProductStock;
use App\Models\User;
use App\Models\VerifiedPaymentEvent;
use App\Services\CommerceSafety\CanonicalCartValidator;
use App\Services\CommerceSafety\CheckoutAuthority;
use App\Services\CommerceSafety\CustomerReplyPolicy;
use App\Services\CommerceSafety\FinancialOutputGuard;
use App\Services\Guardrail\OffTopicCircuitBreaker;
use App\Services\HybridSearchService;
use App\Services\IntentAnalysisService;
use App\Services\LineWebhook\LineWebhookOutputService;
use App\Services\LineWebhook\LineWebhookResponseService;
use App\Services\LineWebhook\WebhookContext;
use App\Services\ModelCapabilityService;
use App\Services\PromptEval\PromptEvalRunner;
use App\Services\StockInjectionService;
use App\Services\VipPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\SkippedWithMessageException;
use Tests\TestCase;

/**
 * Three evidence layers. Saved responses are historical replays, not new inference.
 * No production E2E claim. Image recognition (especially T17) remains a separate gate.
 */
class Bot26PromptEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'openai/gpt-5.6-luna';

    // Image raw skip-list: T16/T30 use canned classifications; T17 additionally has
    // a BLOCKED camera-photo classification gate. Its passing replay is reply handling only.
    private const RAW_IMAGE_SKIP_IDS = ['T16', 'T17', 'T30'];

    private const HASH = '2b52d1cd389c6fc38cf0b415d23ec9832b8dc178fc187811485a35c0fa197062';

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Event::fake();
        config(['rag.semantic_cache.enabled' => false, 'circuit-breaker.enabled' => false, 'commerce_safety.bots.26.mode' => 'enforce']);
    }

    private static function fixtures(): array
    {
        $records = [];
        foreach (glob(__DIR__.'/../../Fixtures/PromptEval/bot26-v28/[TX]*.json') as $path) {
            $case = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $records[$case['id']] = $case;
        }

        return $records;
    }

    public static function textCases(): array
    {
        return array_map(fn ($case) => [$case], array_filter(self::fixtures(), fn ($case) => $case['layer'] === 'text_replay'));
    }

    /** Fixture-derived application expectations, including guard-replaced cases. */
    public static function fixture_semantics(): array
    {
        return self::textCases();
    }

    public static function imageCases(): array
    {
        return array_map(fn ($case) => [$case], array_filter(self::fixtures(), fn ($case) => $case['layer'] === 'image_handler'));
    }

    private static function prompt(): string
    {
        return file_get_contents(dirname(__DIR__, 3).'/resources/prompts/bot26/v28.txt');
    }

    // LAYER A — offline literal, arithmetic and protocol replay; no persisted fixtures.

    public function test_offline_artifact_and_fixture_inventory(): void
    {
        $path = base_path('resources/prompts/bot26/v28.txt');
        $this->assertFileExists($path);
        $prompt = self::prompt();
        $this->assertSame(23505, mb_strlen($prompt));
        $this->assertSame(59732, strlen($prompt));
        $this->assertSame(self::HASH, hash('sha256', $prompt));
        $manifest = json_decode(file_get_contents(__DIR__.'/../../Fixtures/PromptEval/bot26-v28/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(mb_strlen($prompt), $manifest['prompt']['chars']);
        $this->assertSame(strlen($prompt), $manifest['prompt']['bytes']);
        $this->assertSame(hash('sha256', $prompt), $manifest['prompt']['sha256']);
        $this->assertSame(array_keys(self::fixtures()), array_column($manifest['fixtures'], 'id'));
        $expectedUnmetIds = array_merge(...array_values($manifest['layers']['application']['guard_replacement_cases']));
        sort($expectedUnmetIds);
        $this->assertSame($expectedUnmetIds, array_keys($manifest['layers']['application']['expected_unmet_fixture_semantics']));
        $this->assertSame(count(self::fixture_semantics()), $manifest['layers']['application']['fixture_semantics_cases']);
        foreach ($manifest['fixtures'] as $fixture) {
            $bytes = file_get_contents(__DIR__.'/../../Fixtures/PromptEval/bot26-v28/'.$fixture['id'].'.json');
            $this->assertSame(strlen($bytes), $fixture['bytes']);
            $this->assertSame(mb_strlen($bytes), $fixture['chars']);
            $this->assertSame(hash('sha256', $bytes), $fixture['sha256']);
        }
        $this->assertStringEndsWith("\n", $prompt);
        $ids = array_merge(array_map(fn ($n) => sprintf('T%02d', $n), range(1, 33)), array_map(fn ($n) => sprintf('X%02d', $n), range(1, 8)));
        $this->assertSame($ids, array_keys(self::fixtures()));
        $this->assertCount(41, glob(__DIR__.'/../../Fixtures/PromptEval/bot26-v28/[TX]*.json'));
        $this->assertCount(38, self::textCases());
        $this->assertSame(self::RAW_IMAGE_SKIP_IDS, array_keys(self::imageCases()));
        $this->assertTrue(self::fixtures()['T17']['classification_gate_blocked']);
        $this->assertNotEmpty(self::fixtures()['T17']['classification_gate_blocked_reason']);
        foreach (['Nolimit Level Up+ BM (ผูกบัตร)', 'Nolimit Level Up+ BM (เติมเงิน)', 'Nolimit Level Up+ Personal (ผูกบัตร)', 'Nolimit Level Up+ Personal (เติมเงิน)', 'Page', 'G3D', '223-3-24880-3', 'หจก. มั่งมีทรัพย์ขายของออนไลน์', 'https://mhhacoursecontent.my.canva.site/ads-vance', 'https://lin.ee/h5wYpIf', '@743ddeqy', 'https://t.me/supermanth2022', '[[ORDER]]', '[[/ORDER]]', '[[OFFTOPIC]]', '[แจ้งเตือน Support]', '[ยืนยันชำระเงิน]', '|||'] as $literal) {
            $this->assertStringContainsString($literal, $prompt);
        }
        foreach (self::fixtures() as $case) {
            $this->assertTrue($case['synthetic']);
            $this->assertArrayNotHasKey('conversation_id', $case);
            foreach ($case['history'] as $message) {
                $this->assertSame(['sender', 'content'], array_keys($message));
                $this->assertContains($message['sender'], ['user', 'bot']);
            }
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('bots', 0);
        $this->assertDatabaseCount('conversations', 0);
    }

    #[DataProvider('textCases')]
    public function test_offline_saved_response_literals_arithmetic_and_protocol(array $case): void
    {
        $this->assertSame([], $this->responseFailures($case, $case['evidence']['response']), $case['id']);
        $this->assertSame(self::MODEL, $case['evidence']['model']);
        $this->assertSame('medium', $case['evidence']['settings']['reasoning']['effort']);
        $this->assertFalse($case['evidence']['settings']['provider']['allow_fallbacks']);
        $this->assertNotEmpty($case['evidence']['request_id']);
        $this->assertSame('stop', $case['evidence']['finish_reason']);
        // final2 used an earlier prompt. Never label its outputs as exact-artifact runs.
        $this->assertNotSame(self::HASH, $case['provenance']['prompt_sha256']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
    }

    /** Shared assertions are recomputed from text, never copied boolean verdicts. */
    private function responseFailures(array $case, string $text): array
    {
        $failures = [];
        if (trim($text) === '') {
            $failures[] = 'empty response';
        }
        $bot = new Bot;
        $bot->id = 26;
        $contacts = app(CustomerReplyPolicy::class)->allowedContacts($bot);
        preg_match_all('~https?://[^\s<>"\x{201D}]+~u', $text, $urls);
        foreach ($urls[0] as $url) {
            if (! in_array(rtrim($url, '.,)'), $contacts['urls'], true)) {
                $failures[] = 'unapproved URL: '.$url;
            }
        }
        preg_match_all('/@[a-zA-Z0-9_.-]+/', $text, $handles);
        foreach ($handles[0] as $handle) {
            if (! in_array($handle, $contacts['handles'], true)) {
                $failures[] = 'unapproved handle: '.$handle;
            }
        }
        $assertions = $case['assertions'];
        foreach ($assertions['contains'] as $literal) {
            // Numeric formatting is not a semantic difference (2,200 vs 2200).
            $found = preg_match('/^[0-9,]+$/', $literal)
                ? str_contains(str_replace(',', '', $text), str_replace(',', '', $literal))
                : str_contains($text, $literal);
            if (! $found) {
                $failures[] = 'missing: '.$literal;
            }
        }
        foreach ($assertions['not_contains'] as $literal) {
            if (str_contains($text, $literal)) {
                $failures[] = 'forbidden: '.$literal;
            }
        }
        if ($assertions['items'] !== null) {
            $sum = 0;
            foreach ($assertions['items'] as $item) {
                $price = str_starts_with($item['name'], 'Nolimit') ? ($case['system_injections']['vip'] ? 1000 : 1100) : (['Page' => 199, 'G3D' => 50][$item['name']] ?? null);
                if (! is_int($item['qty']) || $item['qty'] < 1 || $item['price'] !== $price) {
                    $failures[] = 'noncanonical fixture item';
                }
                $sum += $item['qty'] * $item['price'] * 100;
            }
            if ($sum !== $assertions['total'] * 100) {
                $failures[] = 'fixture arithmetic';
            }
            if (! str_contains(str_replace(',', '', $text), (string) $assertions['total'])) {
                $failures[] = 'total absent';
            }
        }
        if ($assertions['order_block']) {
            $matches = [];
            $count = preg_match_all('/\[\[ORDER\]\](.*?)\[\[\/ORDER\]\]/su', $text, $matches);
            if ($count !== 1 || ! str_ends_with(trim($text), $matches[0][0] ?? 'INVALID') || str_contains($matches[1][0] ?? '', "\n")) {
                $failures[] = 'ORDER must be one single-line final block';
            }
            $payload = json_decode($matches[1][0] ?? '', true);
            if ($payload !== ['items' => $assertions['items'], 'total' => $assertions['total']]) {
                $failures[] = 'ORDER canonical items/total/types';
            }
        }

        return $failures;
    }

    public function test_offline_assertions_reject_marker_and_arithmetic_mutations(): void
    {
        $case = self::fixtures()['T15'];
        $this->assertNotEmpty($this->responseFailures($case, str_replace('"total":2399', '"total":1', $case['evidence']['response'])));
        $this->assertNotEmpty($this->responseFailures($case, $case['evidence']['response']."\nextra"));
        $case = self::fixtures()['T33'];
        $this->assertNotEmpty($this->responseFailures($case, $case['evidence']['response'].'[ยืนยันชำระเงิน]'));
        Http::assertNothingSent();
    }

    // LAYER B — real persisted application response/output handlers + checkout authority.
    // Only inference, transport, asynchronous jobs and broadcasting are faked.

    private function persistApplication(array $case): void
    {
        config([
            'commerce_safety.bots.26.mode' => 'enforce',
            'delivery.order_payload_enabled' => true,
            'delivery.enabled' => false,
            'services.openrouter.api_key' => 'synthetic-not-a-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.provider_preferences' => [],
        ]);
        $owner = User::factory()->owner()->create(['email' => 'bot26-eval@example.invalid']);
        $this->bot = Bot::factory()->active()->line()->create([
            'id' => 26, 'user_id' => $owner->id, 'name' => 'Synthetic Bot 26',
            'channel_access_token' => 'synthetic-token', 'primary_chat_model' => self::MODEL,
            'fallback_chat_model' => null, 'context_window' => 40, 'reasoning_effort' => 'medium',
        ]);
        $flow = Flow::factory()->default()->create(['id' => 24, 'bot_id' => 26, 'system_prompt' => self::prompt(), 'name' => 'Synthetic Flow 24']);
        $this->bot->update(['default_flow_id' => $flow->id]);
        $plugin = FlowPlugin::create(['flow_id' => 24, 'name' => 'Synthetic order', 'type' => 'order', 'enabled' => true, 'trigger_condition' => 'always', 'config' => []]);
        config(['commerce_safety.bots.26.payment_plugin_ids' => [$plugin->id]]);
        $this->conversation = Conversation::factory()->create([
            'bot_id' => 26, 'current_flow_id' => 24, 'customer_profile_id' => null,
            'external_customer_id' => 'eval-user', 'channel_type' => 'line', 'status' => 'active',
            'is_handover' => false, 'last_message_at' => now(),
            'memory_notes' => $this->memoryNotes($case),
        ]);
        foreach ($this->catalog($case) as $product) {
            $product->save();
        }
        $this->persistHistory($case);
        $this->persistKnowledge($case, $flow);
        $this->mock(IntentAnalysisService::class)->shouldReceive('analyzeIntent')->andReturn(['intent' => $case['system_injections']['kb'] === '' ? 'chat' : 'knowledge', 'confidence' => 1, 'usage' => null]);
        $capabilities = $this->mock(ModelCapabilityService::class);
        $capabilities->shouldReceive('supportsReasoning', 'supportsVision', 'supportsStructuredOutput')->andReturn(true);
        $capabilities->shouldReceive('getDefaultReasoningEffort')->andReturn('medium');
        if ($case['system_injections']['stock'] === 'unknown') {
            $this->partialMock(StockInjectionService::class)->shouldReceive('getStockStatus')->andReturn(collect());
        }
    }

    /** Only these fixtures encode a consent stage in their history/label. */
    private function expectedCheckoutState(array $case): ?string
    {
        return match ($case['id']) {
            'T12', 'X06' => 'awaiting_support',
            'T13', 'T14' => 'awaiting_terms',
            'T15' => 'payable',
            default => null,
        };
    }

    private function persistHistory(array $case): void
    {
        $authority = app(CheckoutAuthority::class);
        $outcome = null;
        foreach ($case['history'] as $index => $row) {
            $this->travel(1)->seconds();
            $message = $this->conversation->messages()->create($row + ['type' => 'text', 'event_timestamp' => now()->getTimestampMs()]);
            if ($this->expectedCheckoutState($case) === null) {
                continue;
            }
            // Seed only the cart actually encoded in the fixture's first summary.
            // Then replay its consent messages via the authority with persisted challenges.
            if ($index === 1) {
                preg_match_all('/(Nolimit Level Up\+ (?:Personal|BM) \(ผูกบัตร\)|Page)\s+(?:(\d+) ตัว ราคาต่อหน่วย ([\d,]+) บาท|× (\d+) = ([\d,]+) บาท)/u', $row['content'], $matches, PREG_SET_ORDER);
                $this->assertNotEmpty($matches, $case['id'].' must encode the initial cart');
                $lines = array_map(function (array $match): array {
                    $qty = (int) ($match[2] !== '' ? $match[2] : $match[4]);
                    $price = $match[3] !== '' ? (int) str_replace(',', '', $match[3]) : (int) str_replace(',', '', $match[5]) / $qty;

                    return ['name' => str_replace(' (ผูกบัตร)', '', $match[1]), 'method' => $match[1] === 'Page' ? 'none' : 'card', 'qty' => $qty, 'price_minor' => (int) ($price * 100)];
                }, $matches);
                $total = array_sum(array_map(fn ($line) => $line['qty'] * $line['price_minor'], $lines));
                $cart = app(CanonicalCartValidator::class)->validate($this->bot, $this->conversation, $lines, $total);
                $this->assertTrue($cart->valid, implode(', ', $cart->errors));
                $outcome = $authority->propose($this->bot, $this->conversation, $cart);
            } elseif ($index > 1 && $row['sender'] === 'user') {
                $outcome = $authority->accept($this->bot, $this->conversation, $message);
            }
            if ($row['sender'] === 'bot' && $outcome?->checkout) {
                $authority->pending($outcome->checkout, $outcome->checkout->revision, $message, $outcome->action);
                $authority->presented($outcome->checkout, $outcome->checkout->revision, $message);
            }
        }
    }

    private function persistKnowledge(array $case, Flow $flow): void
    {
        $content = $case['system_injections']['kb'];
        if ($content === '') {
            return;
        }
        $kb = KnowledgeBase::create(['user_id' => $this->bot->user_id, 'name' => 'Synthetic '.$case['id'], 'document_count' => 1, 'chunk_count' => 1]);
        $flow->knowledgeBases()->attach($kb->id);
        $document = $kb->documents()->create([
            'filename' => 'synthetic.txt', 'original_filename' => 'synthetic.txt',
            'mime_type' => 'text/plain', 'file_size' => strlen($content),
            'storage_path' => 'fixtures/synthetic.txt', 'status' => 'completed', 'chunk_count' => 1,
        ]);
        $chunk = $document->chunks()->create(['content' => $content, 'chunk_index' => 0]);
        $search = $this->mock(HybridSearchService::class);
        $search->shouldReceive('isEnabled')->andReturn(false);
        $search->shouldReceive('searchMultiple')->once()
            ->withArgs(fn ($configs, $query, $limit, $apiKey) => array_column($configs, 'id') === [$kb->id] && $query === $case['message'])
            ->andReturnUsing(fn () => collect([[
                'content' => $chunk->fresh()->content, 'document_name' => $document->original_filename,
                'knowledge_base_id' => $kb->id, 'similarity' => 1.0,
            ]]));
        $this->assertDatabaseHas('flow_knowledge_base', ['flow_id' => 24, 'knowledge_base_id' => $kb->id]);
        $this->assertDatabaseHas('document_chunks', ['id' => $chunk->id, 'content' => $content]);
    }

    private function memoryNotes(array $case): array
    {
        $notes = [];
        if ($case['system_injections']['vip']) {
            $notes[] = ['source' => 'vip_manual', 'content' => 'Synthetic VIP'];
        }
        if ($case['system_injections']['memory'] !== '') {
            $notes[] = ['source' => 'manual', 'type' => 'memory', 'content' => $case['system_injections']['memory']];
        }

        return $notes;
    }

    private function catalog(array $case): Collection
    {
        return collect([
            ['name' => 'Nolimit Level Up+ Personal', 'slug' => 'personal', 'stock_code' => 'NLMP', 'aliases' => ['Personal'], 'price' => '1100.00', 'vip_price' => '1000.00', 'delivery_method' => 'stock'],
            ['name' => 'Nolimit Level Up+ BM', 'slug' => 'bm', 'stock_code' => 'NLMBM', 'aliases' => ['BM'], 'price' => '1100.00', 'vip_price' => '1000.00', 'delivery_method' => 'stock'],
            ['name' => 'Page', 'slug' => 'page', 'stock_code' => 'PAGE', 'aliases' => ['เพจ'], 'price' => '199.00', 'vip_price' => null, 'delivery_method' => 'support_link'],
            ['name' => 'G3D', 'slug' => 'g3d', 'stock_code' => 'G3D', 'aliases' => ['ไก่'], 'price' => '50.00', 'vip_price' => null, 'delivery_method' => 'stock'],
        ])->map(fn ($row, $index) => new ProductStock($row + [
            'in_stock' => ! ($case['system_injections']['stock'] === 'bm_out' && $row['slug'] === 'bm'),
            'manual_off' => false, 'available_count' => $row['slug'] === 'page' ? null : 10, 'display_order' => $index,
        ]));
    }

    private function fakeTransport(string $response, ?array $slip = null, int $slipStatus = 400): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'id' => 'eval-request', 'model' => self::MODEL,
                'choices' => [['message' => ['content' => $response], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
            ]),
            'api.line.me/*' => Http::response(['sentMessages' => [['id' => 'eval-line-message']]]),
            'api.easyslip.com/*' => Http::response($slip ?? ['message' => 'INVALID_IMAGE_TYPE'], $slipStatus),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
    }

    private function handler(string $message, string $type = 'text', array $metadata = []): WebhookContext
    {
        $this->travel(1)->seconds();
        $this->conversation->refresh();
        $this->bot->refresh();
        $id = 'eval-'.($this->conversation->messages()->count() + 1);
        $event = ['type' => 'message', 'replyToken' => $id, 'source' => ['type' => 'user', 'userId' => 'eval-user'], 'message' => ['id' => $id, 'type' => $type, 'text' => $message], 'webhookEventId' => $id, 'deliveryContext' => ['isRedelivery' => false], 'timestamp' => now()->getTimestampMs()];
        $ctx = new WebhookContext($this->bot, $event);
        $ctx->conversation = $this->conversation;
        $ctx->userMessage = $this->conversation->messages()->create([
            'sender' => 'user', 'type' => $type, 'content' => $message, 'external_message_id' => $id,
            'media_url' => $type === 'image' ? 'https://fixtures.invalid/bot26/'.$id.'.jpg' : null,
            'metadata' => $metadata ?: null, 'event_timestamp' => $event['timestamp'],
        ]);
        app(LineWebhookResponseService::class)->generate($ctx);
        $this->assertNotNull($ctx->response, 'Handler must produce a response');
        app(LineWebhookOutputService::class)->dispatch($ctx);

        return $ctx;
    }

    private function assertSafeOutput(WebhookContext $ctx, bool $allowBank = false): void
    {
        $message = $ctx->metadata['bot_message']->fresh();
        $this->assertNotEmpty($message->content);
        $forbidden = ['[[ORDER]]', '[[/ORDER]]', '[[OFFTOPIC]]', '[ยืนยันชำระเงิน]', 'เงินเข้าแล้ว', '```'];
        if (! $allowBank) {
            $forbidden[] = '223-3-24880-3';
        }
        // A server-rendered payable message stores its canonical ORDER internally;
        // the final LINE transport below must strip it even in that case.
        $persistedForbidden = $allowBank ? array_diff($forbidden, ['[[ORDER]]', '[[/ORDER]]']) : $forbidden;
        foreach ($persistedForbidden as $literal) {
            $this->assertStringNotContainsString($literal, $message->content);
            $this->assertStringNotContainsString($literal, (string) $ctx->response->payload);
        }
        foreach (Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.line.me/')) as [$request]) {
            $json = json_encode($request->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            foreach ($forbidden as $literal) {
                $this->assertStringNotContainsString($literal, $json);
            }
        }
        $this->assertSame([], app(CustomerReplyPolicy::class)->apply($this->bot, $message->content)['reasons']);
    }

    private function assertNoPaymentEffects(): void
    {
        foreach (['orders', 'verified_payment_events', 'payment_effects', 'account_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        Queue::assertNotPushed(ReserveAccountStock::class);
        Queue::assertNotPushed(SendDeliveryCard::class);
    }

    #[DataProvider('fixture_semantics')]
    public function test_application_replays_saved_text_through_real_handlers(array $case): void
    {
        $this->persistApplication($case);
        $this->fakeTransport($case['evidence']['response']);
        $ctx = $this->handler($case['message']);
        $this->assertSafeOutput($ctx, allowBank: $case['assertions']['order_block']);
        $this->assertApplicationScenario($case, $ctx);
        $this->assertNoPaymentEffects();
        if (in_array($case['id'], ['T12', 'T13', 'T15'], true)) {
            // These exact consent replies are consumed by the persisted checkout authority.
            Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'openrouter.ai'));
        } else {
            Http::assertSent(function (Request $request) use ($case): bool {
                if (! str_contains($request->url(), 'openrouter.ai/api/v1/chat/completions')) {
                    return false;
                }
                $messages = implode("\n", array_column($request['messages'], 'content'));
                $this->assertSame(self::MODEL, $request['model']);
                $this->assertStringContainsString(self::prompt(), $messages);
                foreach (['memory', 'kb'] as $injection) {
                    if ($case['system_injections'][$injection] !== '') {
                        $this->assertStringContainsString($case['system_injections'][$injection], $messages, $case['id'].' '.$injection);
                    }
                }
                if ($case['system_injections']['stock'] !== 'unknown') {
                    $this->assertStringContainsString(app(StockInjectionService::class)->buildStockInjection($this->catalog($case)), $messages);
                }
                if ($case['system_injections']['vip']) {
                    $this->assertStringContainsString(app(VipPricingService::class)->buildPromptBlock($this->conversation, $this->catalog($case)), $messages);
                }

                return true;
            });
        }
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.line.me/') && isset($request['messages']));
        $this->assertSame(self::HASH, hash('sha256', Flow::findOrFail(24)->system_prompt));
    }

    /**
     * Measured application interlocks, NOT alternate prompt expectations. The original
     * fixture assertions run against both application outputs and record unmet semantics.
     * Guard checks remain separate from that manifest-pinned semantic result.
     * See docs/testing/bot26-v28-evaluation.md for the resulting semantic coverage gaps.
     */
    private function applicationGuardReplacement(array $case): ?string
    {
        return match ($case['id']) {
            // Existing proposal parser rejects these saved prose formats.
            'T01', 'T03', 'T04', 'T06', 'T07', 'T11', 'T20', 'X03', 'X04', 'X05' => 'ระบบตรวจสอบรายการนี้ไม่ได้อย่างชัดเจนครับ กรุณาระบุชื่อสินค้า จำนวน และวิธีรับสินค้าใหม่อีกครั้ง',
            // Financial-word interlock also rejects negated payment claims.
            'T19', 'T22', 'T23', 'T33', 'X02' => FinancialOutputGuard::DENIAL,
            // Historical date prose is rejected by the existing contact policy.
            'T29' => CustomerReplyPolicy::FALLBACK,
            // StockGuard replaces this reply with its unavailable-product response.
            'X08' => "ขออภัยครับ ขณะนี้ Nolimit Level Up+ BM หมด stock ชั่วคราว ไม่สามารถสั่งซื้อได้ครับ\n\nหากสนใจสินค้าอื่น หรือต้องการให้แจ้งเมื่อสินค้ากลับมา สามารถบอกได้เลยครับ",
            default => null,
        };
    }

    private function assertApplicationScenario(array $case, WebhookContext $ctx): void
    {
        $message = $ctx->metadata['bot_message']->fresh();
        $displayCase = $case;
        // Protocol markers are consumed by the application, not customer display text.
        $displayCase['assertions']['contains'] = array_values(array_diff($case['assertions']['contains'], ['[[OFFTOPIC]]']));
        $displayCase['assertions']['order_block'] = false;
        $replacement = $this->applicationGuardReplacement($case);
        if (in_array('[[OFFTOPIC]]', $case['assertions']['contains'], true)) {
            $this->assertSame(1, Cache::get(OffTopicCircuitBreaker::cacheKey(26, $this->conversation->id)));
        }
        $display = trim((string) preg_replace('/\[\[ORDER\]\].*?(?:\[\[\/ORDER\]\]|$)/su', '', $message->content));
        // Fixture items/total specify cart arithmetic, not mandatory unit-price prose.
        $state = $this->expectedCheckoutState($case);
        if ($state !== null) {
            $checkout = CheckoutSession::sole();
            $this->assertSame($state, $checkout->state, $case['id']);
            if ($case['assertions']['total'] !== null) {
                $this->assertSame($case['assertions']['total'] * 100, $checkout->total_minor, $case['id']);
                preg_match('/\[\[ORDER\]\](.*?)\[\[\/ORDER\]\]/su', $message->content, $order);
                $this->assertSame(['items' => $case['assertions']['items'], 'total' => $case['assertions']['total']], json_decode($order[1], true, 512, JSON_THROW_ON_ERROR));
                $this->assertSame(array_column($case['assertions']['items'], 'qty'), array_column($checkout->items, 'qty'));
                $this->assertSame(array_map(fn ($item) => $item['price'] * 100, $case['assertions']['items']), array_column($checkout->items, 'price_minor'));
            }
            if ($state === 'payable') {
                $this->assertSame($ctx->userMessage->id, $checkout->accepted['terms']);
            } else {
                $this->assertArrayNotHasKey('terms', $checkout->accepted, $case['id']);
            }
        }
        // Check the actual LINE text too, so a correct DB reply cannot hide a generic wire reply.
        $wireText = collect(Http::recorded(fn (Request $request) => str_contains($request->url(), 'api.line.me/')))
            ->flatMap(fn ($pair) => $pair[0]['messages'])->pluck('text')->filter()->implode("\n");
        // Always evaluate the fixture semantics, even when a guard replaces the reply.
        // Exact failure lists catch new gaps AND partial/full guard relaxation.
        $fixture_semantics = [
            'persisted' => $this->responseFailures($displayCase, $display),
            'line' => $this->responseFailures($displayCase, $wireText),
        ];
        $unmet_fixture_semantics = array_filter($fixture_semantics, fn ($failures) => $failures !== []);
        $manifest = json_decode(file_get_contents(__DIR__.'/../../Fixtures/PromptEval/bot26-v28/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $expected = $manifest['layers']['application']['expected_unmet_fixture_semantics'][$case['id']] ?? [];
        $dir = storage_path('app/prompt-eval/bot26-v28/application');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir.'/'.$case['id'].'.json', json_encode([
            'layer' => 'application_replay', 'case_id' => $case['id'],
            'fixture_semantics' => $case['assertions'],
            'display_assertions' => $displayCase['assertions'],
            'unmet_fixture_semantics' => $unmet_fixture_semantics,
            'semantic_passed' => $unmet_fixture_semantics === [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        foreach ($fixture_semantics as $surface => $failures) {
            $this->assertSame($expected, $failures, $case['id'].' '.$surface.' unmet_fixture_semantics; update the manifest when semantics change');
        }
        if ($replacement !== null) {
            // Guard safety is a separate assertion target, never a fixture substitution.
            $this->assertSame($replacement, $message->content, $case['id'].' guard replacement');
            $this->assertDatabaseCount('checkout_sessions', 0);
            $this->assertSame($replacement, $wireText, $case['id'].' LINE guard replacement');
        }
    }

    #[DataProvider('imageCases')]
    public function test_application_image_handler_boundary(array $case): void
    {
        $this->persistApplication($case);
        $this->enableSlip();
        // T17 supplies is_slip=false and canned prose only. No camera-photo recognition
        // is exercised; classification_gate_blocked must stay explicit in the fixture.
        if ($case['id'] === 'T17') {
            $this->assertTrue($case['classification_gate_blocked']);
            $this->assertNotEmpty($case['classification_gate_blocked_reason']);
        }
        // Synthetic payment prose triggers EasySlip's unreadable-image classifier branch.
        // It is deliberately NOT checkout or payment authority.
        $this->conversation->messages()->create(['sender' => 'bot', 'type' => 'text', 'content' => "สรุปรายการ\n1. Page (199 x 1) = 199 บาท\nรวมยอดโอน: 199 บาท\n223-3-24880-3"]);
        $this->fakeTransport(json_encode(['is_slip' => $case['image']['is_slip'], 'reply' => $case['image']['reply']], JSON_UNESCAPED_UNICODE));
        $ctx = $this->handler($case['message'], 'image');
        $this->assertSafeOutput($ctx);
        foreach ($case['assertions']['application_integration']['contains'] as $literal) {
            $this->assertStringContainsString($literal, $ctx->metadata['bot_message']->fresh()->content);
        }
        $this->assertNoPaymentEffects();
        foreach ($case['assertions']['application_integration']['not_contains'] as $literal) {
            $this->assertStringNotContainsString($literal, $ctx->metadata['bot_message']->fresh()->content);
        }
        $this->assertTrue($case['raw_model_skipped']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.easyslip.com'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'openrouter.ai') && str_contains(json_encode($request->data()), 'image_url'));
        if ($case['id'] === 'T16') {
            $this->assertDatabaseHas('slip_verifications', ['status' => 'unreadable']);
            Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.telegram.org') && $request['chat_id'] === 'eval-admin');
        } else {
            Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.telegram.org'));
        }
    }

    private function enableSlip(): void
    {
        $this->bot->user->getOrCreateSettings()->update(['easyslip_api_token' => 'synthetic-token']);
        $this->bot->settings()->updateOrCreate(['bot_id' => 26], ['slip_verification_enabled' => true, 'slip_receiver_account' => '223-3-24880-3']);
        $this->bot->unsetRelation('settings')->unsetRelation('user');
        $alert = FlowPlugin::create(['flow_id' => 24, 'name' => 'Synthetic slip alert', 'type' => 'telegram', 'enabled' => true, 'trigger_condition' => 'always', 'config' => ['access_token' => 'synthetic-alert-token', 'chat_id' => 'eval-admin']]);
        config(['commerce_safety.bots.26.payment_plugin_ids' => array_merge(config('commerce_safety.bots.26.payment_plugin_ids'), [$alert->id])]);
    }

    public function test_application_cart_consent_advances_once_and_changed_cart_reconfirms(): void
    {
        $this->persistApplication(self::fixtures()['T01']);
        $this->fakeTransport('สรุปรายการครับ ยืนยันรายการนี้ไหมครับ'."\n".'[[ORDER]]{"items":[{"name":"Nolimit Level Up+ Personal (ผูกบัตร)","qty":2,"price":1100}],"total":2200}[[/ORDER]]');
        $ctx = $this->handler('เอา Personal ผูกบัตร 2 ตัว ไม่เอาเพจ');
        $this->assertSafeOutput($ctx);
        $checkout = CheckoutSession::sole();
        $this->assertSame(1, $checkout->revision);
        $this->assertSame(220000, $checkout->total_minor);
        $this->assertSame('awaiting_confirm', $checkout->state);
        $this->assertNotNull($checkout->presented_at);
        foreach ([['ยืนยัน', 'awaiting_support', 'confirm'], ['ตกลง', 'awaiting_terms', 'support_delay'], ['ยอมรับ', 'payable', 'terms']] as [$reply, $state, $stage]) {
            $ctx = $this->handler($reply);
            $checkout->refresh();
            $this->assertSame($state, $checkout->state);
            $this->assertSame(1, $checkout->revision);
            $this->assertSame($ctx->userMessage->id, $checkout->accepted[$stage]);
            $this->assertSame(1, DB::table('checkout_consent_acceptances')->where('checkout_id', $checkout->id)->where('revision', 1)->where('stage', $stage)->count());
            // Replaying exactly the accepted message never accepts the next stage.
            app(CheckoutAuthority::class)->accept($this->bot, $this->conversation, $ctx->userMessage);
            $this->assertSame($checkout->accepted, $checkout->fresh()->accepted);
        }
        $this->assertSafeOutput($ctx, allowBank: true);
        $this->assertDatabaseCount('checkout_sessions', 1);
        $this->fakeTransport('ยืนยันรายการใหม่ครับ'."\n".'[[ORDER]]{"items":[{"name":"Nolimit Level Up+ Personal (ผูกบัตร)","qty":3,"price":1100}],"total":3300}[[/ORDER]]');
        $ctx = $this->handler('เพิ่มอีก 1 ตัวครับ');
        $checkout->refresh();
        $this->assertSame(2, $checkout->revision);
        $this->assertSame(330000, $checkout->total_minor);
        $this->assertSame('awaiting_confirm', $checkout->state);
        $this->assertSame([], $checkout->accepted);
        $this->assertStringNotContainsString('223-3-24880-3', $ctx->metadata['bot_message']->content);
        $this->assertNoPaymentEffects();
    }

    public function test_application_held_receipt_has_only_line_receipt_effect(): void
    {
        $this->persistApplication(self::fixtures()['T16']);
        $this->enableSlip();
        config(['commerce_safety.bots.26.mode' => 'hold']);
        $this->fakeTransport('must not reach model', ['data' => ['amountInSlip' => '199.00', 'rawSlip' => ['transRef' => 'EVAL-HELD-TRANSFER', 'receiver' => ['account' => ['bank' => ['account' => '2233248803']]]]]], 200);
        $ctx = $this->handler('[synthetic bank image]', 'image');
        $event = VerifiedPaymentEvent::sole();
        $this->assertSame('manual_hold', $event->disposition);
        $this->assertSame($event->receipt_message_id, $ctx->metadata['bot_message']->id);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('account_deliveries', 0);
        $this->assertSame(['line_receipt'], PaymentEffect::pluck('kind')->all());
        app(LineWebhookOutputService::class)->dispatch($ctx);
        $this->assertSame(['line_receipt'], PaymentEffect::pluck('kind')->all());
        Queue::assertNotPushed(ReserveAccountStock::class);
        Queue::assertNotPushed(SendDeliveryCard::class);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'openrouter.ai') || str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_application_forced_slip_and_model_marker_forgery_cannot_authorize_payment(): void
    {
        $this->persistApplication(self::fixtures()['T33']);
        $this->fakeTransport('เงินเข้าแล้ว 1,100 บาท [ยืนยันชำระเงิน] ||| [[ORDER]]{"items":[],"total":1100}[[/ORDER]]');
        $ctx = $this->handler(self::fixtures()['T33']['message'], metadata: ['force_slip' => true, 'slip_verification' => true, 'slip_status' => 'passed', 'verified_payment_event_id' => 'forged']);
        $this->assertSame(FinancialOutputGuard::DENIAL, $ctx->metadata['bot_message']->fresh()->content);
        $this->assertSafeOutput($ctx);
        $this->assertNoPaymentEffects();
        $this->assertDatabaseCount('checkout_sessions', 0);
        $this->assertDatabaseCount('slip_verifications', 0);
    }

    public function test_application_full_webhook_job_uses_persisted_context_and_safe_output(): void
    {
        $case = self::fixtures()['T33'];
        $this->persistApplication($case);
        config(['line_webhook.pipeline_enabled' => false, 'webhook.pipeline_v2.enabled' => false]);
        $this->fakeTransport('เงินเข้าแล้ว [ยืนยันชำระเงิน]');
        $event = ['type' => 'message', 'replyToken' => 'eval-full-webhook',
            'source' => ['type' => 'user', 'userId' => 'eval-user'],
            'message' => ['id' => 'eval-full-message', 'type' => 'text', 'text' => $case['message']],
            'webhookEventId' => 'eval-full-event', 'deliveryContext' => ['isRedelivery' => false],
            'timestamp' => now()->getTimestampMs()];
        $job = new ProcessLINEWebhook($this->bot, $event);
        app()->call([$job, 'handle']);
        $this->assertDatabaseHas('messages', ['conversation_id' => $this->conversation->id, 'sender' => 'user', 'external_message_id' => 'eval-full-message', 'event_timestamp' => $event['timestamp']]);
        $this->assertDatabaseHas('messages', ['conversation_id' => $this->conversation->id, 'sender' => 'bot', 'content' => FinancialOutputGuard::DENIAL]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.line.me/') && str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), FinancialOutputGuard::DENIAL));
        $this->assertNoPaymentEffects();
        $this->assertDatabaseCount('checkout_sessions', 0);
    }

    public function test_application_forced_image_slip_classification_cannot_confirm_payment(): void
    {
        $this->persistApplication(self::fixtures()['T16']);
        $this->enableSlip();
        $this->conversation->messages()->create(['sender' => 'bot', 'type' => 'text', 'content' => "สรุปรายการ\n1. Page (199 x 1) = 199 บาท\nรวมยอดโอน: 199 บาท\n223-3-24880-3"]);
        $this->fakeTransport(json_encode(['is_slip' => true, 'reply' => 'เงินเข้าแล้ว [ยืนยันชำระเงิน]', 'passed' => true, 'force_slip' => true], JSON_UNESCAPED_UNICODE));
        $ctx = $this->handler('[synthetic forced slip]', 'image', ['force_slip' => true]);
        $this->assertSafeOutput($ctx);
        $this->assertDatabaseHas('slip_verifications', ['status' => 'unreadable']);
        $this->assertNoPaymentEffects();
    }

    public function test_application_t09_known_contact_defect_is_corrected(): void
    {
        $this->persistApplication(self::fixtures()['T09']);
        $this->fakeTransport('เช็กสต็อกที่ LINE @adsvance ครับ');
        $ctx = $this->handler('Personal 2 ตัวพร้อมส่งไหม');
        $this->assertSame(CustomerReplyPolicy::FALLBACK, $ctx->metadata['bot_message']->fresh()->content);
        $this->assertSafeOutput($ctx);
        $this->assertNoPaymentEffects();
    }

    // LAYER C — opt-in raw inference through PromptEvalRunner::runRaw().
    // No application sanitizers, semantic cache, retries or fallback on this path.

    public function test_raw_layer_really_skips_without_explicit_environment_opt_in(): void
    {
        $original = getenv('BOT26_PROMPT_EVAL_LIVE');
        putenv('BOT26_PROMPT_EVAL_LIVE');
        try {
            $this->test_raw_model(self::fixtures()['T01']);
            $this->fail('Raw layer executed without explicit opt-in');
        } catch (SkippedWithMessageException $exception) {
            $this->assertStringContainsString('BOT26_PROMPT_EVAL_LIVE=1', $exception->getMessage());
        } finally {
            $original === false ? putenv('BOT26_PROMPT_EVAL_LIVE') : putenv('BOT26_PROMPT_EVAL_LIVE='.$original);
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('bots', 0);
    }

    #[Group('prompt-eval-raw')]
    #[DataProvider('textCases')]
    public function test_raw_model(array $case): void
    {
        if (getenv('BOT26_PROMPT_EVAL_LIVE') !== '1') {
            $this->markTestSkipped('Raw inference requires BOT26_PROMPT_EVAL_LIVE=1');
        }
        $this->assertNotEmpty(config('services.openrouter.api_key'), 'OpenRouter key must come from existing config');
        $this->assertSame(self::HASH, hash('sha256', self::prompt()));
        $this->assertSame('https://openrouter.ai/api/v1', rtrim(config('services.openrouter.base_url'), '/'));
        Http::allowStrayRequests(['https://openrouter.ai/api/v1/chat/completions']);
        $result = $this->rawRequest($case);
        $failures = $this->responseFailures($case, $result['content']);
        foreach (['request_id' => $result['request_id'], 'returned_model' => $result['returned_model'], 'finish_reason' => $result['finish_reason']] as $key => $value) {
            if (! is_string($value) || $value === '') {
                $failures[] = 'missing '.$key;
            }
        }
        if ($result['requested_model'] !== self::MODEL || $result['returned_model'] !== self::MODEL) {
            $failures[] = 'returned model mismatch or fallback';
        }
        if (($result['settings']['reasoning'] ?? null) !== ['effort' => 'medium']
            || ($result['settings']['provider']['allow_fallbacks'] ?? null) !== false
            || ($result['settings']['model'] ?? null) !== self::MODEL
            || isset($result['settings']['models'])) {
            $failures[] = 'wire settings mismatch';
        }
        if ($result['finish_reason'] !== 'stop') {
            $failures[] = 'incomplete generation';
        }
        $dir = storage_path('app/prompt-eval/bot26-v28/'.gmdate('Ymd-His').'-'.getmypid());
        $this->saveRawProvenance($dir, $case, $result, $failures);
        $this->assertSame([], $failures);
    }

    private function saveRawProvenance(string $dir, array $case, array $result, array $failures): string
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir.'/'.$case['id'].'.json';
        file_put_contents($path, json_encode([
            'layer' => 'raw_model', 'case_id' => $case['id'], 'prompt_sha256' => self::HASH,
            'input_sha256' => $result['input_sha256'], 'request_id' => $result['request_id'],
            'requested_model' => $result['requested_model'], 'returned_model' => $result['returned_model'],
            'settings' => $result['settings'], 'finish_reason' => $result['finish_reason'],
            'usage' => $result['usage'],
            'raw_output' => $result['content'], 'assertions' => $case['assertions'],
            'failures' => $failures, 'passed' => $failures === [], 'manual_semantic_review' => 'pending',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function rawRequest(array $case): array
    {
        $products = $this->catalog($case);
        $conversation = new Conversation(['memory_notes' => $this->memoryNotes($case)]);
        $stock = $case['system_injections']['stock'] === 'unknown' ? '' : app(StockInjectionService::class)->buildStockInjection($products);
        $vip = app(VipPricingService::class)->buildPromptBlock($conversation, $products);
        $system = implode("\n\n", array_filter([self::prompt(), $case['system_injections']['memory'], $stock, $vip, $case['system_injections']['kb']]));
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($case['history'] as $message) {
            $messages[] = ['role' => $message['sender'] === 'bot' ? 'assistant' : 'user', 'content' => $message['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $case['message']];

        return app(PromptEvalRunner::class)->runRaw($messages, self::MODEL);
    }

    public function test_raw_provenance_preserves_usage_with_fake_http_only(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key', 'services.openrouter.base_url' => 'https://openrouter.ai/api/v1']);
        $case = self::fixtures()['T01'];
        $usage = [
            'prompt_tokens' => 123, 'completion_tokens' => 45, 'total_tokens' => 168,
            'cost' => 0.00123, 'completion_tokens_details' => ['reasoning_tokens' => 12],
        ];
        Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response([
            'id' => 'synthetic-usage-request', 'model' => self::MODEL,
            'choices' => [['message' => ['content' => $case['evidence']['response']], 'finish_reason' => 'stop']],
            'usage' => $usage,
        ])]);
        $result = $this->rawRequest($case);
        $dir = sys_get_temp_dir().'/bot26-fake-raw-'.bin2hex(random_bytes(8));
        $path = $dir.'/'.$case['id'].'.json';
        try {
            $this->assertSame($path, $this->saveRawProvenance($dir, $case, $result, []));
            $saved = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($usage, $result['usage']);
            $this->assertSame($usage, $saved['usage']);
            $this->assertSame('synthetic-usage-request', $saved['request_id']);
            $this->assertSame($case['evidence']['response'], $saved['raw_output']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('bots', 0);
    }

    public function test_raw_runner_sends_required_settings_with_fake_http_only(): void
    {
        config(['services.openrouter.api_key' => 'synthetic-not-a-key', 'services.openrouter.base_url' => 'https://openrouter.ai/api/v1']);
        $case = self::fixtures()['T06'];
        $this->fakeTransport($case['evidence']['response']);
        $result = $this->rawRequest($case);
        $this->assertSame('eval-request', $result['request_id']);
        $this->assertSame(self::MODEL, $result['requested_model']);
        $this->assertSame(self::MODEL, $result['returned_model']);
        $this->assertSame('stop', $result['finish_reason']);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $system = $request['messages'][0]['content'];

            return $request['model'] === self::MODEL && ! isset($request['models'])
                && $request['reasoning'] === ['effort' => 'medium']
                && $request['provider'] === ['allow_fallbacks' => false]
                && substr_count($system, 'ลูกค้ารายนี้ได้รับสิทธิ VIP จากระบบแล้ว') === 1
                && str_contains($system, '1,000 บาท/ตัว')
                && str_contains($system, app(StockInjectionService::class)->buildStockInjection($this->catalog(self::fixtures()['T06'])));
        });
        $this->assertDatabaseCount('bots', 0);
    }
}
