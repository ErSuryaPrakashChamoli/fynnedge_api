<?php

namespace App\Services;

/**
 * Server-side mirror of the Flutter FinanceService.
 *
 * The app calculates EMIs locally so the numbers never depend on the network,
 * but the API needs the same maths for anything it derives itself. Both
 * implementations use the standard reducing-balance formula, so they agree to
 * the rupee.
 */
class FinanceService
{
    /** EMI = P·r·(1+r)^n / ((1+r)^n − 1) */
    public function emi(float $principal, float $annualRate, int $tenureMonths): float
    {
        if ($principal <= 0 || $tenureMonths <= 0) {
            return 0.0;
        }

        if ($annualRate <= 0) {
            return $principal / $tenureMonths;
        }

        $r = $annualRate / 12 / 100;
        $pow = pow(1 + $r, $tenureMonths);

        return $principal * $r * $pow / ($pow - 1);
    }

    /** @return array{emi: float, total_interest: float, total_payable: float} */
    public function summary(float $principal, float $annualRate, int $tenureMonths): array
    {
        $emi = $this->emi($principal, $annualRate, $tenureMonths);
        $totalPayable = $emi * $tenureMonths;

        return [
            'emi' => round($emi, 2),
            'total_interest' => round(max(0, $totalPayable - $principal), 2),
            'total_payable' => round($totalPayable, 2),
        ];
    }

    public function processingFee(float $amount, float $percent, ?float $cap): float
    {
        $fee = $amount * $percent / 100;

        return $cap !== null && $fee > $cap ? $cap : round($fee, 2);
    }
}
