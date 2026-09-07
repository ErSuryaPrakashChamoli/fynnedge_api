<?php

namespace Tests\Unit;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\ParityCases;
use App\Domain\Match\FynnMatchEngine;
use App\Domain\Match\MatchRequest;
use App\Domain\Money;
use App\Models\FinancialProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The PHP side of the FynnMatch fixture. The Flutter suite asserts the same
 * file, so a change to either engine that the other does not follow breaks a
 * build rather than shipping a quiet divergence.
 */
class FynnMatchParityTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{array<string, mixed>}> */
    public static function cases(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__.'/../fixtures/fynnmatch_parity.json'),
            true,
        );

        $out = [];
        foreach ($fixture['cases'] as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    #[DataProvider('cases')]
    public function test_the_engine_reproduces_the_fixture(array $case): void
    {
        $this->assertSame(
            $case['expected'],
            $this->evaluate($case),
            $case['name'].': '.$case['note'],
        );
    }

    public function test_the_fixture_is_current(): void
    {
        $this->artisan('fynn:parity-fixture', ['--check' => true])
            ->assertSuccessful();
    }

    public function test_identical_inputs_produce_identical_results(): void
    {
        $cases = collect(self::cases())->map(fn (array $c) => $c[0])->keyBy('name');

        $a = $cases['multiple_business_matches'];
        $b = $cases['determinism_repeat'];

        // Same customer, same request, recorded independently.
        $this->assertSame($a['input']['profile'], $b['input']['profile']);
        $this->assertSame($a['input']['request'], $b['input']['request']);
        $this->assertSame($a['expected'], $b['expected']);

        // And running it again now still agrees.
        $this->assertSame($a['expected'], $this->evaluate($b));
    }

    public function test_the_fixture_covers_every_category(): void
    {
        $seen = [];
        foreach (self::cases() as [$case]) {
            foreach ($case['expected']['matches'] as $match) {
                $seen[$match['match']['category']] = true;
            }
        }

        $this->assertSame(
            ['good_match', 'not_a_match', 'review', 'strong_match'],
            collect(array_keys($seen))->sort()->values()->all(),
        );
    }

    /** @return array<string, mixed> */
    private function evaluate(array $case): array
    {
        $snapshot = app(FinancialEngine::class)->snapshot(
            new FinancialProfile($case['input']['profile']),
            collect(),
            Carbon::parse(ParityCases::AS_OF),
        );

        $matches = app(FynnMatchEngine::class)->match(
            new MatchRequest(
                amount: Money::of($case['input']['request']['amount']),
                tenureMonths: $case['input']['request']['tenure_months'],
                purpose: $case['input']['request']['purpose'],
            ),
            $snapshot,
            $case['input']['age'],
        );

        return [
            'matches' => array_map(fn ($m) => $m->toArray(), $matches),
            'match_count' => count(array_filter($matches, fn ($m) => $m->category->isViable())),
            'evaluated_count' => count($matches),
        ];
    }
}
