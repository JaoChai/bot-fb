<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BotSetting;
use App\Models\User;
use App\Support\OutageMessageText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด outage-settings Task B1 — การตั้งค่า "เมื่อบอทล่ม" ผ่าน Settings API
 *
 * R1: คอลัมน์ใหม่ 4 ตัวบน bot_settings (enabled NOT NULL default true + ข้อความ 3 ช่อง nullable)
 * R2: ข้อความ ≤ 500 ตัวอักษร, ค่าว่าง = null (inherit default), '0' ยังเป็นข้อความปกติ,
 *     ปฏิเสธแท็ก HTML / comment / brace template ด้วย 422 (Lead D1: sanitizer ทำงานก่อนเสมอ
 *     — <script> ถูกตัดหายก่อน validate จึงต้องเก็บ "ไม่มีทางเก็บ <script ลง DB")
 * R3: GET/PUT คืน data.outage_defaults = PHP defaults เสมอ, stored override คืนเป็น nullable
 */
class BotOutageSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT_FIELDS = [
        'outage_recovery_message_th',
        'outage_recovery_message_en',
        'outage_down_message',
    ];

    #[Test]
    public function test_get_and_put_include_php_defaults_and_nullable_overrides(): void
    {
        [$user, $bot] = $this->makeBot();

        $get = $this->actingAs($user)->getJson("/api/bots/{$bot->id}/settings");
        $get->assertOk();
        $body = $get->json('data');
        $this->assertSame(OutageMessageText::defaults(), $body['outage_defaults']);
        $this->assertTrue($body['outage_recovery_enabled']);
        $this->assertNull($body['outage_recovery_message_th']);
        $this->assertNull($body['outage_recovery_message_en']);
        $this->assertNull($body['outage_down_message']);
        $this->assertNull($this->storedValue($bot, 'outage_recovery_message_th'));

        $put = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
            'language' => 'en',
        ]);
        $put->assertOk();
        $this->assertSame(OutageMessageText::defaults(), $put->json('data.outage_defaults'));
        $this->assertTrue($put->json('data.outage_recovery_enabled'));
        $this->assertNull($put->json('data.outage_recovery_message_th'));
        $this->assertNull($put->json('data.outage_recovery_message_en'));
        $this->assertNull($put->json('data.outage_down_message'));
    }

    #[Test]
    public function test_owner_updates_each_text_and_switch_without_touching_other_bots(): void
    {
        [$user, $botA] = $this->makeBot();
        [$otherUser, $botB] = $this->makeBot();

        $custom = [
            'outage_recovery_enabled' => false,
            'outage_recovery_message_th' => 'กลับมาแล้วครับ กรุณาพิมพ์ใหม่',
            'outage_recovery_message_en' => 'We are back. Please send again.',
            'outage_down_message' => 'ระบบกำลังตรวจสอบ กรุณารอสักครู่ครับ',
        ];
        $put = $this->actingAs($user)->putJson("/api/bots/{$botA->id}/settings", $custom);
        $put->assertOk();
        $data = $put->json('data');
        $this->assertFalse($data['outage_recovery_enabled']);
        $this->assertSame($custom['outage_recovery_message_th'], $data['outage_recovery_message_th']);
        $this->assertSame($custom['outage_recovery_message_en'], $data['outage_recovery_message_en']);
        $this->assertSame($custom['outage_down_message'], $data['outage_down_message']);

        $this->assertFalse($this->storedValue($botA, 'outage_recovery_enabled'));
        $this->assertSame($custom['outage_recovery_message_th'], $this->storedValue($botA, 'outage_recovery_message_th'));
        $this->assertSame($custom['outage_recovery_message_en'], $this->storedValue($botA, 'outage_recovery_message_en'));
        $this->assertSame($custom['outage_down_message'], $this->storedValue($botA, 'outage_down_message'));

        // บอทอื่นไม่โดนและยังไม่มีแถว settings
        $this->assertSame(0, BotSetting::query()->where('bot_id', $botB->id)->count());

        // PATCH โดยไม่ส่งช่อง outage = ค่าเดิมอยู่ครบ
        $patch = $this->actingAs($user)->patchJson("/api/bots/{$botA->id}/settings", [
            'language' => 'th',
        ]);
        $patch->assertOk();
        $this->assertFalse($this->storedValue($botA, 'outage_recovery_enabled'));
        $this->assertSame($custom['outage_recovery_message_th'], $this->storedValue($botA, 'outage_recovery_message_th'));
        $this->assertSame($custom['outage_down_message'], $this->storedValue($botA, 'outage_down_message'));
    }

    #[Test]
    public function test_blank_normalizes_to_null_but_zero_is_custom(): void
    {
        [$user, $bot] = $this->makeBot();

        foreach (self::TEXT_FIELDS as $field) {
            // '0' = ข้อความปกติ ห้ามกลายเป็น null
            $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [$field => '0'])->assertOk();
            $this->assertSame('0', $this->storedValue($bot, $field), $field.' must store "0"');

            // ค่าว่างทุกรูปแบบ = null (inherit default)
            foreach ([null, '', " \t\n", "\u{00a0}\u{2003}", str_repeat(' ', 501)] as $blank) {
                $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [$field => 'ก่อนหน้านี้'])->assertOk();
                $put = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [$field => $blank]);
                $put->assertOk();
                $this->assertNull($this->storedValue($bot, $field), $field.' must normalize blank to null');
            }
        }
    }

    #[Test]
    public function test_text_limit_is_500_unicode_characters(): void
    {
        [$user, $bot] = $this->makeBot();

        foreach (self::TEXT_FIELDS as $field) {
            foreach (['ก', '🙏'] as $char) {
                $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
                    $field => str_repeat($char, 500),
                ])->assertOk();
                $this->assertSame(str_repeat($char, 500), $this->storedValue($bot, $field));

                $rejected = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
                    $field => str_repeat($char, 501),
                ]);
                $rejected->assertStatus(422);
                $this->assertArrayHasKey($field, $rejected->json('errors'));
                $this->assertSame(str_repeat($char, 500), $this->storedValue($bot, $field), $field.' unchanged after 422');
            }
        }
    }

    #[Test]
    public function test_plain_text_validation_rejects_markup_and_brace_templates(): void
    {
        [$user, $bot] = $this->makeBot();
        $message = 'ใช้ได้เฉพาะข้อความธรรมดา ห้ามใส่แท็ก HTML หรือ {ตัวแปร}';

        foreach (self::TEXT_FIELDS as $field) {
            foreach (['<b>x</b>', '<!--x-->', '{name}', '{{name}}', '${name}'] as $markup) {
                $rejected = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [$field => $markup]);
                $rejected->assertStatus(422);
                $this->assertSame($message, $rejected->json("errors.{$field}.0"), $field.' must carry the D1 message');
                $this->assertNull($this->storedValue($bot, $field), $field.' not stored after 422');
            }

            // D1: sanitizer ตัด <script> ออกก่อน validate — ต้องไม่มีทางเก็บ <script ลง DB เท่านั้น
            $script = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [$field => '<script>x</script>']);
            $script->assertOk();
            $stored = $this->storedValue($bot, $field);
            $this->assertNull($stored, $field.' must not keep a sanitized-empty value');
            $this->assertFalse(str_contains((string) $stored, '<script'), $field.' must never store <script');

            // ข้อความธรรมดาที่อนุญาต: เทียบเครื่องหมาย, คำพูด, ขึ้นบรรทัด, &lt; ตามตัวอักษร
            foreach (['1 < 2 & 3 > 2', 'กลับมาแล้ว "พี่" ส่งใหม่ \'ครับ\'', "บรรทัดแรก\nบรรทัดสอง", '&lt; ตามตัวอักษร'] as $plain) {
                $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [$field => $plain])->assertOk();
                $this->assertSame($plain, $this->storedValue($bot, $field), $field.' must stay literal');
            }

            // ชนิดผิด: array / integer / object = 422
            foreach ([['x'], 5, ['nested' => 'object']] as $wrongType) {
                $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [$field => $wrongType])->assertStatus(422);
            }
        }
    }

    #[Test]
    public function test_switch_rejects_null_and_non_boolean_values(): void
    {
        [$user, $bot] = $this->makeBot();

        foreach ([null, 'false', [], 'not-a-bool'] as $bad) {
            $rejected = $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
                'outage_recovery_enabled' => $bad,
            ]);
            $rejected->assertStatus(422);
            $this->assertArrayHasKey('outage_recovery_enabled', $rejected->json('errors'));
            $this->assertTrue($this->storedValue($bot, 'outage_recovery_enabled') !== false, 'switch must not be flipped by a rejected PUT');
        }

        foreach ([false, 0, '0'] as $off) {
            $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
                'outage_recovery_enabled' => $off,
            ])->assertOk();
            $this->assertFalse($this->storedValue($bot, 'outage_recovery_enabled'));
        }
    }

    #[Test]
    public function test_outage_settings_authorization_is_unchanged(): void
    {
        [$owner, $bot] = $this->makeBot();
        [$stranger, $otherBot] = $this->makeBot();
        $admin = User::factory()->admin()->create();
        $admin->assignedBots()->attach($bot->id);

        foreach (['getJson', 'putJson', 'patchJson'] as $method) {
            $this->{$method}("/api/bots/{$bot->id}/settings")->assertUnauthorized();
        }
        foreach (['getJson', 'putJson', 'patchJson'] as $method) {
            $payload = $method === 'getJson' ? [] : ['outage_recovery_enabled' => false];
            $this->actingAs($stranger)->{$method}("/api/bots/{$bot->id}/settings", $payload)->assertForbidden();
        }

        // Admin ที่ถูกมอบหมาย: GET ได้ตาม policy ปัจจุบัน แต่ PUT/PATCH ห้าม
        // (เจ้าของเปิด GET ก่อนเพื่อให้แถว settings มีอยู่จริงก่อนโดน admin ยิง)
        $this->actingAs($owner)->getJson("/api/bots/{$bot->id}/settings")->assertOk();
        $this->actingAs($admin)->getJson("/api/bots/{$bot->id}/settings")->assertOk();
        $this->actingAs($admin)->putJson("/api/bots/{$bot->id}/settings", [
            'outage_recovery_enabled' => false,
        ])->assertForbidden();
        $this->actingAs($admin)->patchJson("/api/bots/{$bot->id}/settings", [
            'outage_recovery_enabled' => false,
        ])->assertForbidden();
        $this->assertTrue($this->storedValue($bot, 'outage_recovery_enabled') !== false, 'admin must not be able to disable the switch');

        // ปลอมคีย์ฝั่งลูก: bot_id / outage_defaults / metadata ห้ามถูกบันทึก
        $this->actingAs($owner)->putJson("/api/bots/{$bot->id}/settings", [
            'outage_recovery_message_th' => 'ของจริง',
            'bot_id' => $otherBot->id,
            'outage_defaults' => ['outage_down_message' => 'hacked'],
            'metadata' => ['model_error' => 'spoof'],
        ])->assertOk();
        $stored = $this->freshSettings($bot);
        $this->assertSame($bot->id, $stored->bot_id);
        $this->assertSame('ของจริง', $stored->outage_recovery_message_th);
        $this->assertNull($stored->outage_down_message);
    }

    #[Test]
    public function test_missing_settings_row_uses_default_row_creation(): void
    {
        [$user, $bot] = $this->makeBot();
        $this->assertSame(0, BotSetting::query()->where('bot_id', $bot->id)->count());

        $get = $this->actingAs($user)->getJson("/api/bots/{$bot->id}/settings");
        $get->assertOk();
        $this->assertSame(1, BotSetting::query()->where('bot_id', $bot->id)->count());
        $created = $this->freshSettings($bot);
        $this->assertTrue($created->outage_recovery_enabled);
        $this->assertNull($created->outage_recovery_message_th);
        $this->assertNull($created->outage_recovery_message_en);
        $this->assertNull($created->outage_down_message);
        $this->assertSame(OutageMessageText::defaults(), $get->json('data.outage_defaults'));

        $this->actingAs($user)->putJson("/api/bots/{$bot->id}/settings", [
            'outage_down_message' => 'กำลังตรวจสอบครับ',
        ])->assertOk();
        $this->assertSame(1, BotSetting::query()->where('bot_id', $bot->id)->count());
        $this->assertSame('กำลังตรวจสอบครับ', $this->freshSettings($bot)->outage_down_message);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0: User, 1: Bot} */
    private function makeBot(): array
    {
        $user = User::factory()->owner()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        return [$user, $bot];
    }

    private function freshSettings(Bot $bot): BotSetting
    {
        return BotSetting::query()->where('bot_id', $bot->id)->firstOrFail();
    }

    /**
     * ค่าที่เก็บจริงของช่องหนึ่ง — แถวยังไม่เกิด = null (ใช้เช็ค "ไม่มีทางถูกเก็บ")
     */
    private function storedValue(Bot $bot, string $field): mixed
    {
        return BotSetting::query()->where('bot_id', $bot->id)->first()?->{$field};
    }
}
