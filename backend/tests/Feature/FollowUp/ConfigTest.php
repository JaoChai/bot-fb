<?php

namespace Tests\Feature\FollowUp;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * การ์ด B2a — config/customer_follow_up.php ตาม plan §3.2
 *
 * ทุก key ต้องมีค่าตั้งต้นตรงแผน และ 4 key ที่อ่านจาก env ต้อง fail-closed:
 * - quota_reserve_messages: ขาด/ไม่ใช่จำนวนเต็ม/ไม่เป็นบวก → null (null = หยุดส่ง)
 * - automatic_bot_ids: เอาเฉพาะจำนวนเต็มบวก ตัวอื่นทิ้ง → [] เมื่อไม่มีค่า
 * - automatic_min_confidence: ขาด = 0.8 เท่าเดิม; ว่าง/ผิดรูป/นอกช่วง [0,1] → null (กฎ A1 fail closed)
 * - admin_web_url: ยอมรับเฉพาะ https origin → อย่างอื่น null (capability ยังล็อก)
 */
class ConfigTest extends TestCase
{
    private const ENV_KEYS = [
        'CUSTOMER_FOLLOW_UP_LINE_RESERVE',
        'CUSTOMER_FOLLOW_UP_AUTOMATIC_BOT_IDS',
        'CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL',
        'CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE',
    ];

    /**
     * โหลดไฟล์ config จริงภายใต้ env ที่ควบคุมได้ (ตามแบบ RedisRetryConfigTest)
     *
     * @return array<string, mixed>
     */
    private function loadConfig(array $env = []): array
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
        foreach ($env as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            return require config_path('customer_follow_up.php');
        } finally {
            foreach (self::ENV_KEYS as $key) {
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    #[Test]
    public function test_every_key_has_its_plan_default(): void
    {
        $config = $this->loadConfig();

        $this->assertSame([26], $config['bot_ids'], 'round-1 restriction: bot 26 only');
        $this->assertSame('openai/gpt-6-luna-decisions', $config['decisions_model']);
        $this->assertSame(config('services.openrouter.decisions_url'), $config['decisions_url'], 'decisions_url must reuse services.openrouter.decisions_url');
        $this->assertSame(10, $config['decisions_timeout_seconds']);
        $this->assertSame(15, $config['writer_timeout_seconds']);
        $this->assertSame(3, $config['http_connect_timeout_seconds']);
        $this->assertSame(5, $config['line_timeout_seconds']);
        $this->assertSame(400, $config['max_text_code_points']);
        $this->assertSame(1024, $config['writer_max_tokens']);
        $this->assertSame(10, $config['daily_draft_cap']);
        $this->assertSame(3, $config['payment_wait_hours']);
        $this->assertSame(24, $config['sale_wait_hours']);
        $this->assertSame(7, $config['customer_gap_days']);
        $this->assertSame(12, $config['approval_ttl_hours']);
        $this->assertSame(0.8, $config['automatic_min_confidence']);
        $this->assertSame(0.5, $config['declined_threshold']);
        $this->assertSame(0.5, $config['check_yes_threshold']);
        $this->assertNull($config['quota_reserve_messages'], 'reserve unset by default: no push');
        $this->assertSame([], $config['automatic_bot_ids'], 'no automatic bot by default');
        $this->assertNull($config['admin_web_url'], 'no admin web origin by default');

        $this->assertSame(
            ['bot_ids', 'decisions_model', 'decisions_url', 'decisions_timeout_seconds', 'writer_timeout_seconds',
                'http_connect_timeout_seconds', 'line_timeout_seconds', 'max_text_code_points', 'writer_max_tokens',
                'daily_draft_cap', 'payment_wait_hours', 'sale_wait_hours', 'customer_gap_days', 'approval_ttl_hours',
                'automatic_min_confidence', 'declined_threshold', 'check_yes_threshold', 'quota_reserve_messages',
                'automatic_bot_ids', 'admin_web_url'],
            array_keys($config),
            'config must hold exactly the plan §3.2 keys, nothing more'
        );
    }

    #[Test]
    public function test_quota_reserve_messages_env_cases(): void
    {
        $read = fn (array $env) => $this->loadConfig($env)['quota_reserve_messages'];

        $this->assertNull($read([]), 'absent env must give null (send stop)');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_LINE_RESERVE' => 'abc']), 'non-integer must give null');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_LINE_RESERVE' => '0']), 'zero is not positive: null');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_LINE_RESERVE' => '-5']), 'negative is not positive: null');
        $this->assertSame(100, $read(['CUSTOMER_FOLLOW_UP_LINE_RESERVE' => '100']), 'positive integer passes through');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_LINE_RESERVE' => true]), 'env() parses "true" as bool: bool must never become 1, null');
    }

    #[Test]
    public function test_automatic_bot_ids_env_cases(): void
    {
        $read = fn (array $env) => $this->loadConfig($env)['automatic_bot_ids'];

        $this->assertSame([], $read([]), 'absent env must give an empty list');
        $this->assertSame([26], $read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_BOT_IDS' => '26']), 'single positive id passes');
        $this->assertSame([26], $read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_BOT_IDS' => '26,x,-1']), 'only positive integers are kept');
    }

    #[Test]
    public function test_automatic_min_confidence_env_cases(): void
    {
        $read = fn (array $env) => $this->loadConfig($env)['automatic_min_confidence'];

        $this->assertSame(0.8, $read([]), 'absent env must keep the current default 0.8 (merge alone must not change behavior)');
        $this->assertSame(0.5, $read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE' => '0.5']), 'valid value in range passes through');
        $this->assertSame(0.0, $read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE' => '0']), 'lower bound 0 is in range');
        $this->assertSame(1.0, $read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE' => '1']), 'upper bound 1 is in range');
        $this->assertSame(0.5, $read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE' => ' 0.5 ']), 'surrounding whitespace is trimmed, not rejected');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE' => '']), 'empty env must fail closed (null, never a looser value)');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE' => 'abc']), 'non-numeric must fail closed');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE' => '1.5']), 'above range must fail closed');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE' => '-0.2']), 'below range must fail closed');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_AUTOMATIC_MIN_CONFIDENCE' => true]), 'env() parses "true" as bool: bool must never become a threshold, null');
    }

    #[Test]
    public function test_admin_web_url_env_cases(): void
    {
        $read = fn (array $env) => $this->loadConfig($env)['admin_web_url'];

        $this->assertNull($read([]), 'absent env must give null (capability blocked)');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL' => 'http://admin.example.com']), 'http is not an https origin: null');
        $this->assertSame('https://admin.example.com', $read(['CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL' => 'https://admin.example.com']), 'https origin passes through');
        $this->assertSame('https://admin.example.com:8443', $read(['CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL' => 'https://admin.example.com:8443']), 'https origin with port is kept');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL' => 'https://admin.example.com/chats?x=1']), 'path+query is not an origin: null');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL' => 'https://admin.example.com/path']), 'path is not an origin: null');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL' => 'https://admin.example.com/#frag']), 'fragment is not an origin: null');
        $this->assertNull($read(['CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL' => 'https://u:p@admin.example.com']), 'userinfo is not an origin: null');
        $this->assertSame('https://admin.example.com', $read(['CUSTOMER_FOLLOW_UP_ADMIN_WEB_URL' => 'https://admin.example.com/']), 'a lone trailing slash is accepted and stored without it');
    }
}
