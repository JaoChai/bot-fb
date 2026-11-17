<?php

namespace Tests\Feature\Contracts;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\FlowPlugin;
use App\Models\User;
use App\Services\FollowUp\FollowUpCapabilities;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * การ์ด B5a — สัญญา settings ฝั่ง follow-up (Lead D66)
 *
 * ตรวจ response จริงของ GET / successful PUT / successful PATCH / 422 ปฏิเสธการเปิด /
 * 403 / 401 กับ contracts/openapi.yaml, ตรวจ capabilities ของ blocked_reason ทั้งเจ็ดโค้ด
 * + null กับ CustomerFollowUpCapabilities และแสดงว่า data ที่ขาด follow_up_capabilities
 * หรือ blocked_reason นอก enum ต้อง FAIL
 */
class FollowUpContractTest extends TestCase
{
    use RefreshDatabase;

    // contracts/openapi.yaml อยู่ราก repo (เหนือ backend/ หนึ่งชั้น)
    private const CONTRACT_PATH = '../contracts/openapi.yaml';

    private const NOW = '2026-11-16 10:00:00';

    private array $contract;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'Asia/Bangkok'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::NOW, 'Asia/Bangkok'));
        Http::preventStrayRequests();

        $path = base_path(self::CONTRACT_PATH);

        if (! is_file($path)) {
            $this->fail(sprintf(
                'Contract file missing: %s. These tests validate live API payloads against '
                .'the committed OpenAPI contract; the file must exist.',
                $path
            ));
        }

        $this->contract = Yaml::parseFile($path);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * บอทพื้นฐานเหมือน SettingsApiTest — LINE + pilot + plugin (token/chat id/allowlist)
     * + secret/writer/admin_url + แถว settings ปิด โหมด approve สองข้อความ
     *
     * @return array{0: User, 1: Bot}
     */
    private function makeBaseline(): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna',
        ]);

        // append เข้ารายชื่อ pilot — makeBaseline ตัวที่สองต้องไม่ทับบอทตัวแรก
        config(['customer_follow_up.bot_ids' => array_values(array_unique(array_merge(
            (array) config('customer_follow_up.bot_ids'),
            [$bot->id]
        )))]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => 'whsec-'.'b5ac'.'-webhook-secret']);

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);
        FlowPlugin::create([
            'flow_id' => $flow->id,
            'type' => 'telegram',
            'name' => 'แจ้งเตือน',
            'enabled' => true,
            'trigger_condition' => 'always',
            'config' => [
                'access_token' => 'tgk-'.'b5ac'.'-alert-token',
                'chat_id' => '-100'.'4568',
                'authorized_user_ids' => ['987'.'65433'],
            ],
        ]);

        DB::table('bot_settings')->insert([
            'bot_id' => $bot->id,
            'follow_up_enabled' => false,
            'follow_up_mode' => 'approve',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return [$user, $bot];
    }

    /** บอทใหม่ตามโค้ด blocked_reason ที่ต้องการ (เหมือน CapabilitiesTest) */
    private function capabilitiesPayloadFor(string $reason): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->active()->line()->create([
            'user_id' => $user->id,
            'primary_chat_model' => 'openai/gpt-6-luna',
        ]);

        config(['customer_follow_up.bot_ids' => [$bot->id]]);
        config(['customer_follow_up.admin_web_url' => 'https://admin.example.test']);
        config(['services.telegram_alert.secret' => 'whsec-'.'b5ac'.'-webhook-secret']);

        $flow = Flow::factory()->create(['bot_id' => $bot->id]);
        $bot->update(['default_flow_id' => $flow->id]);

        if ($reason !== 'telegram_missing') {
            $config = [
                'access_token' => 'tgk-'.'b5ac'.'-alert-token',
                'chat_id' => '-100'.'4568',
            ];
            if ($reason === 'telegram_allowlist_missing') {
                // D150: รูปร่าง CLOSED (string) ยังให้ telegram_allowlist_missing เหมือนเดิม
                $config['authorized_user_ids'] = '555';
            } else {
                $config['authorized_user_ids'] = ['987'.'65433'];
            }
            FlowPlugin::create([
                'flow_id' => $flow->id,
                'type' => 'telegram',
                'name' => 'แจ้งเตือน',
                'enabled' => true,
                'trigger_condition' => 'always',
                'config' => $config,
            ]);
        }

        match ($reason) {
            'line_only' => $bot->update(['channel_type' => 'facebook']),
            'bot_not_in_pilot' => config(['customer_follow_up.bot_ids' => [999999]]),
            'telegram_missing' => null,
            'telegram_allowlist_missing' => null,
            'webhook_secret_missing' => config(['services.telegram_alert.secret' => '']),
            'writer_missing' => $bot->update(['primary_chat_model' => '', 'fallback_chat_model' => null]),
            'admin_url_missing' => config(['customer_follow_up.admin_web_url' => null]),
            default => null,
        };

        return FollowUpCapabilities::forBot($bot->refresh(), CarbonImmutable::now());
    }

    // ------------------------------------------------------------------
    // ตัวตรวจจริงกับ contract
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data
     * @return string[] รายการ error; ว่าง = valid
     */
    private function validateAgainst(array $data, string $schemaName): array
    {
        $wrapper = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'urn:botjao:follow-up-contract:tests',
            'components' => [
                'schemas' => $this->contract['components']['schemas'] ?? null,
            ],
            'allOf' => [
                ['$ref' => '#/components/schemas/'.$schemaName],
            ],
        ];

        $validator = new Validator;
        $result = $validator->validate(
            json_decode(json_encode($data), false),
            json_decode(json_encode($wrapper), false)
        );

        if ($result->isValid()) {
            return [];
        }

        return array_map(
            fn ($error) => is_string($error) ? $error : (string) $error,
            (new ErrorFormatter)->formatFlat($result->error())
        );
    }

    private function assertValidAgainstContract(array $data, string $schemaName, string $context): void
    {
        $errors = $this->validateAgainst($data, $schemaName);

        $this->assertSame([], $errors, sprintf(
            "%s: payload does not match contracts/openapi.yaml #/components/schemas/%s:\n%s",
            $context,
            $schemaName,
            implode("\n", $errors)
        ));
    }

    private function assertInvalidAgainstContract(array $data, string $schemaName, string $context): void
    {
        $errors = $this->validateAgainst($data, $schemaName);

        $this->assertNotSame([], $errors, sprintf(
            '%s: payload unexpectedly matched #/components/schemas/%s — the contract is not strict enough.',
            $context,
            $schemaName
        ));
    }

    // ------------------------------------------------------------------
    // เทสต์
    // ------------------------------------------------------------------

    #[Test]
    public function test_get_response_matches_contract(): void
    {
        [$user, $bot] = $this->makeBaseline();

        $response = $this->actingAs($user)->getJson("/api/bots/{$bot->id}/settings");

        $response->assertOk();
        $this->assertValidAgainstContract($response->json(), 'GetBotSettingsResponse', 'settings GET 200');
    }

    #[Test]
    public function test_successful_put_response_matches_contract(): void
    {
        [$user, $bot] = $this->makeBaseline();

        $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", ['follow_up_enabled' => true]);

        $response->assertOk();
        $this->assertValidAgainstContract($response->json(), 'UpdateBotSettingsResponse', 'settings PUT 200');
        $this->assertValidAgainstContract(['follow_up_enabled' => true], 'UpdateBotSettingsRequest', 'settings PUT request body');
    }

    #[Test]
    public function test_successful_patch_response_matches_contract(): void
    {
        [$user, $bot] = $this->makeBaseline();

        $response = $this->actingAs($user)->patchJson("/api/bots/{$bot->id}/settings", ['hitl_enabled' => true]);

        $response->assertOk();
        $this->assertValidAgainstContract($response->json(), 'UpdateBotSettingsResponse', 'settings PATCH 200');
    }

    #[Test]
    public function test_refused_enable_422_matches_contract(): void
    {
        [$user, $bot] = $this->makeBaseline();
        // D150: รูปร่าง CLOSED (map) — ยังปฏิเสธการเปิดด้วย telegram_allowlist_missing เหมือนเดิม
        FlowPlugin::query()->update(['config' => json_encode([
            'access_token' => 'tgk-'.'b5ac'.'-alert-token',
            'chat_id' => '-100'.'4568',
            'authorized_user_ids' => ['a' => '555'],
        ])]);

        $response = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", ['follow_up_enabled' => true]);

        $response->assertStatus(422);
        $this->assertSame('telegram_allowlist_missing', $response->json('errors.follow_up_enabled.0'));
        $this->assertValidAgainstContract($response->json(), 'ValidationError', 'settings PUT 422 refused enable');
    }

    #[Test]
    public function test_other_owner_403_matches_contract(): void
    {
        [$user, $bot] = $this->makeBaseline();
        [$otherUser] = $this->makeBaseline();

        $response = $this->actingAs($otherUser)->putJson("/api/bots/{$bot->id}/settings", ['follow_up_enabled' => true]);

        $response->assertForbidden();
        $this->assertValidAgainstContract($response->json(), 'ForbiddenError', 'settings PUT 403');
    }

    #[Test]
    public function test_anon_401_matches_contract(): void
    {
        [$user, $bot] = $this->makeBaseline();

        $response = $this->getJson("/api/bots/{$bot->id}/settings");

        $response->assertUnauthorized();
        $this->assertValidAgainstContract($response->json(), 'UnauthorizedError', 'settings GET 401');
    }

    #[Test]
    public function test_capability_object_for_each_blocked_reason_and_null_matches_contract(): void
    {
        foreach ([
            null,
            'line_only',
            'bot_not_in_pilot',
            'telegram_missing',
            'telegram_allowlist_missing',
            'webhook_secret_missing',
            'writer_missing',
            'admin_url_missing',
        ] as $reason) {
            $payload = $this->capabilitiesPayloadFor((string) $reason);

            $this->assertSame($reason, $payload['blocked_reason'], 'fixture must produce '.var_export($reason, true));
            $this->assertValidAgainstContract($payload, 'CustomerFollowUpCapabilities', 'capabilities for '.var_export($reason, true));
        }
    }

    #[Test]
    public function test_data_without_follow_up_capabilities_fails_validation(): void
    {
        [$user, $bot] = $this->makeBaseline();

        $response = $this->actingAs($user)->getJson("/api/bots/{$bot->id}/settings");
        $response->assertOk();

        $data = $response->json('data');
        unset($data['follow_up_capabilities']);

        $this->assertInvalidAgainstContract($data, 'CustomerFollowUpSettingsFields', 'data without follow_up_capabilities');
        $this->assertInvalidAgainstContract(['data' => $data], 'GetBotSettingsResponse', 'GET envelope without follow_up_capabilities in data');
    }

    #[Test]
    public function test_blocked_reason_outside_enum_fails_validation(): void
    {
        [$user, $bot] = $this->makeBaseline();

        $payload = FollowUpCapabilities::forBot($bot->refresh(), CarbonImmutable::now());
        $payload['blocked_reason'] = 'totally_unknown_reason';

        $this->assertInvalidAgainstContract($payload, 'CustomerFollowUpCapabilities', 'blocked_reason outside the enum');
    }
}
