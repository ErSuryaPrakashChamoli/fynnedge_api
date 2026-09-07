<?php

namespace App\Domain\Credit\Fixture;

use App\Domain\Credit\CreditBureau;
use App\Domain\Credit\CreditBureauAdapter;
use App\Domain\Credit\CreditBureauUnavailable;
use App\Models\User;

/**
 * A FICTIONAL bureau, used to prove the credit boundary works.
 *
 * NO LIVE BUREAU IS CONNECTED TO FYNNEDGE. Nothing here reaches a network,
 * no real person's credit information is involved, and every profile it
 * produces is marked as sample data.
 *
 * The response deliberately uses a bureau's own vocabulary, and deliberately
 * carries far more than FynnEdge keeps — a name, an address, an employer, a
 * list of accounts and enquiries. That surplus exists so the normalizer can
 * be shown discarding it: a real report contains all of this, and almost
 * none of it is any of FynnEdge's business.
 */
class FixtureCreditBureauAdapter implements CreditBureauAdapter
{
    public const HEALTHY = 742;

    public const LOW = 598;

    public function __construct(
        private readonly ?int $score = null,
        private readonly bool $unavailable = false,
        private readonly ?array $response = null,
    ) {}

    public function bureau(): CreditBureau
    {
        return CreditBureau::Fixture;
    }

    public function fetch(User $user): array
    {
        if ($this->unavailable) {
            throw new CreditBureauUnavailable(
                CreditBureau::Fixture,
                'the fixture was configured to be unreachable',
            );
        }

        if ($this->response !== null) {
            return $this->response;
        }

        return self::report($this->score ?? self::HEALTHY);
    }

    /**
     * A bureau-shaped response, in a bureau's vocabulary.
     *
     * Every value is invented. The personal details are here because a real
     * report has them and the normalizer has to be seen throwing them away.
     *
     * @return array<string, mixed>
     */
    public static function report(int $score): array
    {
        return [
            'creditScore' => $score,
            'scoreType' => 'SAMPLE-SCORE-V1',
            'scoreRange' => ['minimum' => 300, 'maximum' => 900],
            'reportDate' => '2026-09-06T10:15:00+05:30',
            'bureauReference' => 'SMP-'.str_pad((string) $score, 6, '0', STR_PAD_LEFT),

            // Everything below is what a real report carries and FynnEdge
            // does not keep. Present so the normalizer can be tested
            // dropping it.
            'consumerName' => 'Sample Person',
            'dateOfBirth' => '1990-01-01',
            'pan' => 'AAAAA0000A',
            'mobile' => '9000000000',
            'addresses' => [
                ['line1' => '1 Sample Street', 'city' => 'Sampleton'],
            ],
            'employment' => ['employer' => 'Sample Employer Pvt Ltd'],
            'accounts' => [
                [
                    'accountNumber' => '00000000001234',
                    'lender' => 'Sample Bank',
                    'balance' => 250000,
                    'daysPastDue' => 0,
                ],
            ],
            'enquiries' => [
                ['date' => '2026-06-01', 'purpose' => 'Personal Loan'],
            ],
        ];
    }
}
