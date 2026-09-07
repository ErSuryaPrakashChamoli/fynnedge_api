<?php

namespace Tests\Unit;

use App\Services\FinanceService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the backend engine against the reducing-balance formula AND
 * against the values the Flutter engine produces. If these two drift, the
 * app and the API would quote different EMIs for the same loan.
 *
 * The Flutter side asserts the same reference figures in
 * fynnedge/test/finance_test.dart.
 */
class FinanceServiceTest extends TestCase
{
    private FinanceService $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = new FinanceService;
    }

    /**
     * Runs the loan forward month by month without using the closed-form
     * formula, so this is an independent check.
     */
    private function simulateClosingBalance(
        float $principal,
        float $annualRate,
        int $months,
        float $emi,
    ): float {
        $monthlyRate = $annualRate / 12 / 100;
        $balance = $principal;

        for ($m = 0; $m < $months; $m++) {
            $balance = $balance + ($balance * $monthlyRate) - $emi;
        }

        return $balance;
    }

    public static function loanProvider(): array
    {
        return [
            '10L @ 13.25% / 60mo' => [1000000.0, 13.25, 60],
            '5L @ 11.5% / 60mo' => [500000.0, 11.5, 60],
            '8L @ 12.4% / 48mo' => [800000.0, 12.4, 48],
            '25L @ 8.6% / 240mo' => [2500000.0, 8.6, 240],
            '3L @ 18% / 24mo' => [300000.0, 18.0, 24],
        ];
    }

    #[DataProvider('loanProvider')]
    public function test_amortisation_closes_to_zero(
        float $principal,
        float $rate,
        int $months,
    ): void {
        $emi = $this->finance->emi($principal, $rate, $months);
        $closing = $this->simulateClosingBalance($principal, $rate, $months, $emi);

        $this->assertLessThan(1.0, abs($closing));
    }

    public function test_reference_case_matches_the_flutter_engine(): void
    {
        // The figure quoted throughout the product docs.
        $summary = $this->finance->summary(1000000, 13.25, 60);

        $this->assertEqualsWithDelta(22881.26, $summary['emi'], 0.5);
        $this->assertEqualsWithDelta(
            $summary['emi'] * 60,
            $summary['total_payable'],
            0.5,
        );
        $this->assertEqualsWithDelta(
            $summary['total_payable'] - 1000000,
            $summary['total_interest'],
            0.5,
        );
    }

    public function test_second_reference_case_matches(): void
    {
        $summary = $this->finance->summary(500000, 11.5, 60);
        $this->assertEqualsWithDelta(10996.30, $summary['emi'], 0.5);
    }

    public function test_zero_rate_splits_principal_evenly(): void
    {
        $this->assertEqualsWithDelta(
            10000.0,
            $this->finance->emi(120000, 0, 12),
            0.01,
        );
    }

    public function test_degenerate_inputs_return_zero(): void
    {
        $this->assertSame(0.0, $this->finance->emi(0, 12, 60));
        $this->assertSame(0.0, $this->finance->emi(100, 12, 0));
    }

    public function test_processing_fee_respects_the_cap(): void
    {
        // 1% of 10L is 10,000 — under the 25,000 cap.
        $this->assertSame(10000.0, $this->finance->processingFee(1000000, 1.0, 25000));
        // 1% of 50L is 50,000 — capped.
        $this->assertSame(25000.0, $this->finance->processingFee(5000000, 1.0, 25000));
        // No cap set.
        $this->assertSame(17500.0, $this->finance->processingFee(1000000, 1.75, null));
    }

    public function test_longer_tenure_lowers_emi_but_costs_more(): void
    {
        $short = $this->finance->summary(1000000, 13.25, 36);
        $long = $this->finance->summary(1000000, 13.25, 84);

        $this->assertLessThan($short['emi'], $long['emi']);
        $this->assertGreaterThan($short['total_interest'], $long['total_interest']);
    }
}
