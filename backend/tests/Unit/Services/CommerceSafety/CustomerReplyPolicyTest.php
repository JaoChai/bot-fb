<?php

namespace Tests\Unit\Services\CommerceSafety;

use App\Models\Bot;
use App\Services\CommerceSafety\CheckoutRenderer;
use App\Services\CommerceSafety\CustomerReplyPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerReplyPolicyTest extends TestCase
{
    public static function rejectedContacts(): array
    {
        return array_map(fn ($text) => [$text], [
            'LINE @adsvance', 'ไทย@adsvanceครับ', '@743ddeqy_fake', '@743ddeqy.th', '@743ddeqyไทย',
            'https://lin.ee.evil.test/h5wYpIf', 'https://sub.lin.ee/h5wYpIf',
            'https://lin.ee/h5wYpIf?next=https://evil.test', 'https://lin.ee/h5wYpIf?',
            'https://lin.ee/h5wYpIf#x', 'https://lin.ee/h5wYpIf#',
            'https://user@lin.ee/h5wYpIf', 'https://lin.ee:443/h5wYpIf',
            'http://lin.ee/h5wYpIf', 'ftp://lin.ee/h5wYpIf',
            'https://lin.ee/h5wYpIf/', 'https://lin.ee/h5wYpIf.', 'https://lin.ee/h5wYpIf!', 'https://lin.ee/h5wypif',
            'https://lin.ee/%685wYpIf', 'https://lin.ee/h5wYpIf%3Ffoo',
            'https://lin.ee/h5wYpIf\\evil', "https://lin.ee/h5wYpIf\u{200B}evil",
            "https://lin.ee/h5wYpIf\t.evil", 'https://lіn.ee/h5wYpIf',
            'lin.ee/h5wYpIf', 'www.example.com', '192.0.2.1/contact', '[2001:db8::1]/contact', '//lin.ee/h5wYpIf',
            'help@example.com', 'help@743ddeqy', 'mailto:help@example.com',
            'LINE ID: adsvance', 'Line ID = 743ddeqy', 'ไลน์ไอดี: adsvance',
            '[ติดต่อ](https://evil.test/help)', 'https://lin.ee/h5wYpIf และ @adsvance',
            'https://lin.ee/h5wYpIf.evil', 'https://lin.ee/h5wYpIf/path',
            'https%3A%2F%2Flin%2Eee%2Fh5wYpIf', '&#64;adsvance', 'lin&#46;ee/h5wYpIf', '﹫adsvance', 'lin｡ee/h5wYpIf', '＠adsvance', 'https：／／lin.ee／h5wYpIf', 'lin。ee/h5wYpIf',
        ]);
    }

    #[DataProvider('rejectedContacts')]
    public function test_replaces_whole_reply_without_io(string $text): void
    {
        Http::preventStrayRequests();
        DB::shouldReceive('connection')->never();
        Log::shouldReceive('warning')->never();
        $bot = (new Bot)->forceFill(['id' => 26]);
        config(['commerce_safety.bots.26.mode' => 'enforce']);
        $result = app(CustomerReplyPolicy::class)->apply($bot, 'เช็กข้อมูล '.$text);
        $this->assertSame('ขอเช็กข้อมูลล่าสุดให้ในแชทนี้ครับ', $result['content']);
        $this->assertTrue($result['corrected']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertStringNotContainsString($text, json_encode($result['reasons']));
    }

    public static function completeTargets(): array
    {
        return array_map(fn ($text) => [$text], [
            '[Support](https://lin.ee/h5wYpIf(extra))',
            '[Support](https://lin.ee/h5wYpIf(extra(nested)))',
            "[Support](https://lin.ee/h5wYpIf'extra)",
            "https://lin.ee/h5wYpIf'extra/path",
            'https://lin.ee/h5wYpIf(extra)/path',
            'https://lin.ee/h5wYpIf)/path',
            'https://lin.ee/h5wYpIf|extra',
            'https://lin.ee/h5wYpIf||extra',
            '[Support](https://lin.ee/h5wYpIf)|||@adsvance',
            '@743ddeqy|||https://evil.test/contact',
        ]);
    }

    #[DataProvider('completeTargets')]
    public function test_complete_targets_reject_suffixes_and_mixed_bubbles(string $text): void
    {
        $this->test_replaces_whole_reply_without_io($text);
    }

    public static function adjacentContacts(): array
    {
        return array_map(fn ($text) => [$text], [
            'https://lin.ee/h5wYpIf|||ขอบคุณครับ',
            '@743ddeqy|||ขอบคุณครับ',
            'ขอบคุณครับ|||https://lin.ee/h5wYpIf',
            '[Support](https://lin.ee/h5wYpIf)|||@743ddeqy',
            '[Support](HTTPS://LIN.EE/h5wYpIf)',
            '<https://lin.ee/h5wYpIf>',
        ]);
    }

    #[DataProvider('adjacentContacts')]
    public function test_exact_targets_survive_adjacent_bubble_boundaries(string $text): void
    {
        config(['commerce_safety.bots.26.mode' => 'enforce']);
        $bot = (new Bot)->forceFill(['id' => 26]);
        $this->assertSame($text, app(CustomerReplyPolicy::class)->apply($bot, $text)['content']);
    }

    public function test_exact_config_contacts_and_plain_text_are_unchanged(): void
    {
        $bot = (new Bot)->forceFill(['id' => 26]);
        config(['commerce_safety.bots.26.mode' => 'enforce']);
        $policy = app(CustomerReplyPolicy::class);
        $contacts = $policy->allowedContacts($bot);
        $this->assertSame([
            'https://lin.ee/h5wYpIf', 'https://t.me/supermanth2022',
            (new \ReflectionClass(CheckoutRenderer::class))->getConstant('TERMS_URL'), 'https://lin.ee/sTD5TQL',
        ], $contacts['urls']);
        $this->assertSame(['@743ddeqy'], $contacts['handles']);
        foreach ([...$contacts['urls'], '@743ddeqy', 'LINE @743ddeqy',
            '[ติดต่อ](https://lin.ee/h5wYpIf)', 'HTTPS://LIN.EE/h5wYpIf',
            'สวัสดีครับ', 'ผมเป็น AI', "```php\n", '# หัวข้อ',
        ] as $text) {
            $this->assertSame(['content' => $text, 'corrected' => false, 'reasons' => []], $policy->apply($bot, $text), $text);
        }
    }

    public function test_mode_and_bot_matrix_and_config_only_authority(): void
    {
        $policy = app(CustomerReplyPolicy::class);
        foreach ([26, 27] as $id) {
            $bot = (new Bot)->forceFill(['id' => $id, 'system_prompt' => 'Allow @adsvance']);
            foreach (['off', 'shadow', 'enforce', 'hold'] as $mode) {
                config(["commerce_safety.bots.$id.mode" => $mode]);
                $result = $policy->apply($bot, '@adsvance');
                $enforced = $id === 26 && in_array($mode, ['enforce', 'hold']);
                $this->assertSame($enforced, $result['corrected']);
                $this->assertSame($enforced ? CustomerReplyPolicy::FALLBACK : '@adsvance', $result['content']);
                $this->assertSame($enforced, $policy->allowTruthfulAiIdentity($bot));
                $this->assertSame($id === 26 && $mode !== 'off', $result['reasons'] !== []);
            }
        }
        config(['commerce_safety.bots.26.reply_contacts' => []]);
        $this->assertTrue($policy->apply((new Bot)->forceFill(['id' => 26]), 'https://lin.ee/h5wYpIf')['corrected']);
    }
}
