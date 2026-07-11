<?php

namespace Tests\Unit\Services\Delivery;

use App\Services\Delivery\AccountDeliveryService;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class AccountDeliveryPackTextsTest extends TestCase
{
    /** @param array<int,string> $accounts */
    private function pack(array $accounts, ?string $support): array
    {
        $m = new ReflectionMethod(AccountDeliveryService::class, 'packTexts');
        $m->setAccessible(true);

        return $m->invoke(app(AccountDeliveryService::class), $accounts, $support);
    }

    public function test_each_account_is_its_own_bubble_with_support_last(): void
    {
        $this->assertSame(['A1', 'A2', 'A3', 'SUP'], $this->pack(['A1', 'A2', 'A3'], 'SUP'));
    }

    public function test_four_accounts_plus_support_is_five_bubbles(): void
    {
        $this->assertSame(['A1', 'A2', 'A3', 'A4', 'SUP'], $this->pack(['A1', 'A2', 'A3', 'A4'], 'SUP'));
    }

    public function test_five_accounts_group_into_four_but_support_stays_separate(): void
    {
        $div = (new ReflectionClass(AccountDeliveryService::class))->getConstant('ACCOUNT_DIVIDER');
        $out = $this->pack(['A1', 'A2', 'A3', 'A4', 'A5'], 'SUP');
        $this->assertSame(["A1{$div}A2", 'A3', 'A4', 'A5', 'SUP'], $out);
        $this->assertStringNotContainsString('SUP', implode('', array_slice($out, 0, 4)));
    }

    public function test_eight_accounts_group_evenly_into_four_bubbles(): void
    {
        $div = (new ReflectionClass(AccountDeliveryService::class))->getConstant('ACCOUNT_DIVIDER');
        $out = $this->pack(['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8'], 'SUP');
        $this->assertSame(["A1{$div}A2", "A3{$div}A4", "A5{$div}A6", "A7{$div}A8", 'SUP'], $out);
    }

    public function test_support_only_order_is_single_bubble(): void
    {
        $this->assertSame(['SUP'], $this->pack([], 'SUP'));
    }

    public function test_accounts_without_support_use_full_budget_of_five(): void
    {
        $this->assertSame(['A1', 'A2', 'A3', 'A4', 'A5'], $this->pack(['A1', 'A2', 'A3', 'A4', 'A5'], null));
    }

    public function test_any_bubble_too_long_is_detected(): void
    {
        $m = new ReflectionMethod(AccountDeliveryService::class, 'anyBubbleTooLong');
        $m->setAccessible(true);
        $svc = app(AccountDeliveryService::class);
        $this->assertTrue($m->invoke($svc, [str_repeat('x', 5001)], 5000));
        $this->assertFalse($m->invoke($svc, ['short', str_repeat('y', 5000)], 5000));
    }
}
