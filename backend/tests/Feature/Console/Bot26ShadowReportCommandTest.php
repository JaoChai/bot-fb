<?php

namespace Tests\Feature\Console;

use App\Models\CommerceSafetyShadowObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Bot26ShadowReportCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('bot26:shadow-report', Artisan::all());
    }

    #[Test]
    public function test_requires_since_and_until(): void
    {
        $this->artisan('bot26:shadow-report', ['--bot' => '26'])
            ->expectsOutputToContain('since_and_until_are_required')
            ->assertFailed();

        $this->artisan('bot26:shadow-report', ['--bot' => '26', '--since' => '2026-01-01'])
            ->expectsOutputToContain('since_and_until_are_required')
            ->assertFailed();
    }

    #[Test]
    public function test_rejects_an_invalid_bot_option(): void
    {
        $this->artisan('bot26:shadow-report', ['--bot' => 'not-a-number', '--since' => '2026-01-01', '--until' => '2026-01-02'])
            ->expectsOutputToContain('bot_option_invalid')
            ->assertFailed();
    }

    #[Test]
    public function test_rejects_unparseable_dates(): void
    {
        $this->artisan('bot26:shadow-report', ['--bot' => '26', '--since' => 'not-a-date-xyz', '--until' => '2026-01-02'])
            ->expectsOutputToContain('since_until_must_be_parseable_dates')
            ->assertFailed();
    }

    #[Test]
    public function test_empty_window_reports_zero_counts_with_window_and_data_source(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shadow-report-').'.json';

        $this->artisan('bot26:shadow-report', [
            '--bot' => '26', '--since' => '2026-01-01T00:00:00Z', '--until' => '2026-01-02T00:00:00Z', '--json' => $path,
        ])->assertSuccessful();

        $report = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unlink($path);

        $this->assertSame(26, $report['bot_id']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $report['window']['since']);
        $this->assertSame('2026-01-02T00:00:00+00:00', $report['window']['until']);
        $this->assertSame(0, $report['total_observations']);
        $this->assertSame([], $report['decisions_by_outcome_reason']);
        $this->assertSame(['valid' => 0, 'invalid' => 0], $report['cart_proposal_summary']);
        $this->assertSame(0, $report['price_mismatch_count']);
        $this->assertSame(0, $report['stock_mismatch_count']);
        $this->assertSame(0, $report['consent_mismatch_count']);
        $this->assertSame(0, $report['paid_hold_candidates']);
        $this->assertSame(0, $report['effect_intents']);
        $this->assertStringContainsString('commerce_safety_shadow_observations', $report['data_source']);
        $this->assertNotEmpty($report['notes']);
    }

    #[Test]
    public function test_populated_window_aggregates_counts_by_category_outcome_and_reason(): void
    {
        $inWindow = '2026-02-10 12:00:00';
        $outsideWindow = '2026-03-01 00:00:00';

        $this->row(26, 'cart_proposal', 'valid', null, $inWindow);
        $this->row(26, 'cart_proposal', 'valid', null, $inWindow);
        $this->row(26, 'cart_proposal', 'invalid', 'PRICE_MISMATCH', $inWindow);
        $this->row(26, 'cart_proposal', 'invalid', 'OUT_OF_STOCK', $inWindow);
        $this->row(26, 'cart_proposal', 'invalid', 'OUT_OF_STOCK', $inWindow);
        $this->row(26, 'cart_proposal', 'invalid', 'PRICE_MISMATCH', $outsideWindow);

        $path = tempnam(sys_get_temp_dir(), 'shadow-report-').'.json';
        $this->artisan('bot26:shadow-report', [
            '--bot' => '26', '--since' => '2026-02-01', '--until' => '2026-02-28', '--json' => $path,
        ])->assertSuccessful();
        $report = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unlink($path);

        $this->assertSame(5, $report['total_observations']);
        $this->assertSame(['valid' => 2, 'invalid' => 3], $report['cart_proposal_summary']);
        $this->assertSame(1, $report['price_mismatch_count']);
        $this->assertSame(2, $report['stock_mismatch_count']);

        $decisions = collect($report['decisions_by_outcome_reason'])->keyBy(fn ($row) => $row['category'].'|'.$row['outcome'].'|'.($row['reason'] ?? ''));
        $this->assertSame(2, $decisions['cart_proposal|valid|']['count']);
        $this->assertSame(1, $decisions['cart_proposal|invalid|PRICE_MISMATCH']['count']);
        $this->assertSame(2, $decisions['cart_proposal|invalid|OUT_OF_STOCK']['count']);
    }

    #[Test]
    public function test_report_scopes_to_the_requested_bot_and_redacts_to_fixed_codes_only(): void
    {
        $this->row(26, 'cart_proposal', 'invalid', 'PRICE_MISMATCH', '2026-02-10 12:00:00');
        $this->row(27, 'cart_proposal', 'invalid', 'PRICE_MISMATCH', '2026-02-10 12:00:00');

        $path = tempnam(sys_get_temp_dir(), 'shadow-report-').'.json';
        $this->artisan('bot26:shadow-report', [
            '--bot' => '26', '--since' => '2026-02-01', '--until' => '2026-02-28', '--json' => $path,
        ])->assertSuccessful();
        $report = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $raw = file_get_contents($path);
        unlink($path);

        $this->assertSame(1, $report['total_observations']);
        $this->assertSame(26, $report['bot_id']);
        // Redaction: only fixed enum-like codes ever appear, never customer text/PII.
        $this->assertStringNotContainsString('@', $raw);
        $this->assertStringNotContainsString('line.me', $raw);
        $this->assertStringNotContainsString('บาท', $raw);
    }

    private function row(int $botId, string $category, string $outcome, ?string $reason, string $createdAt): void
    {
        $row = new CommerceSafetyShadowObservation;
        $row->forceFill([
            'bot_id' => $botId, 'category' => $category, 'outcome' => $outcome, 'reason' => $reason,
        ]);
        $row->created_at = $createdAt;
        $row->save();
    }
}
