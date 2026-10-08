<?php

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\AccountDeliveryService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class AccountDeliveryPackRoundTest extends TestCase
{
    /** @param array<int,string> $accounts */
    private function packRound(array $accounts, ?string $support): array
    {
        $m = new ReflectionMethod(AccountDeliveryService::class, 'packRound');
        $m->setAccessible(true);

        return $m->invoke(app(AccountDeliveryService::class), $accounts, $support);
    }

    public function test_round_accounts_join_into_a_single_bubble(): void
    {
        $div = (new ReflectionClass(AccountDeliveryService::class))->getConstant('ACCOUNT_DIVIDER');

        $this->assertSame(["A1{$div}A2{$div}A3"], $this->packRound(['A1', 'A2', 'A3'], null));
    }

    public function test_support_stays_its_own_bubble_after_joined_accounts(): void
    {
        $div = (new ReflectionClass(AccountDeliveryService::class))->getConstant('ACCOUNT_DIVIDER');

        $this->assertSame(["A1{$div}A2", 'SUP'], $this->packRound(['A1', 'A2'], 'SUP'));
    }

    public function test_oversized_round_splits_into_fewest_balanced_bubbles(): void
    {
        $div = (new ReflectionClass(AccountDeliveryService::class))->getConstant('ACCOUNT_DIVIDER');
        // 5 × 1700 ตัวอักษร: รวมก้อนเดียว 8568 > 5000, แบ่ง 2 ก้อน (3+2 = 5134) ยังเกิน
        // → น้อยที่สุดคือ 3 ก้อน แบบสมดุล 2+2+1
        $accounts = array_map(fn ($i) => str_repeat("x{$i}", 425), range(1, 5));
        $accounts = array_map(fn ($s) => str_pad($s, 1700, 'y'), $accounts);

        $out = $this->packRound($accounts, null);

        $this->assertCount(3, $out);
        $this->assertSame([2, 2, 1], array_map(
            fn ($b) => substr_count($b, $div) + 1,
            $out,
        ));
        foreach ($out as $bubble) {
            $this->assertLessThanOrEqual(5000, mb_strlen($bubble));
        }
    }

    public function test_round_that_cannot_fit_even_at_max_bubbles_returns_max_groups_for_assertfits_to_throw(): void
    {
        $div = (new ReflectionClass(AccountDeliveryService::class))->getConstant('ACCOUNT_DIVIDER');
        // 6 × 3000 ตัวอักษร + support: งบ 4 ก้อน (มี support ร่วม push) แบ่งได้สูงสุด 2+2+1+1
        // แต่ก้อนละ 2 บัญชี = 6017 > 5000 → เป็นไปไม่ได้ → คืนงบสูงสุด 4 ก้อน ให้ assertFitsSinglePush throw เอง
        $accounts = array_map(fn ($i) => str_pad((string) $i, 3000, 'x'), range(1, 6));

        $out = $this->packRound($accounts, 'SUP');

        $this->assertCount(5, $out);
        $this->assertSame('SUP', $out[4]);
        $this->assertSame([2, 2, 1, 1], array_map(fn ($b) => substr_count($b, $div) + 1, array_slice($out, 0, 4)));
        $this->assertGreaterThan(5000, mb_strlen($out[0]));
    }
}
