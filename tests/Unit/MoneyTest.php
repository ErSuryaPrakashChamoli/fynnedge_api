<?php

namespace Tests\Unit;

use App\Domain\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_parses_decimal_strings_exactly(): void
    {
        $this->assertSame(10000055, Money::fromDecimalString('100000.55')->paise);
        $this->assertSame(100, Money::fromDecimalString('1')->paise);
        $this->assertSame(10, Money::fromDecimalString('0.1')->paise);
        $this->assertSame(1, Money::fromDecimalString('0.01')->paise);
        $this->assertSame(0, Money::fromDecimalString('0')->paise);
        $this->assertSame(-2550, Money::fromDecimalString('-25.50')->paise);
    }

    public function test_a_third_decimal_rounds_half_up(): void
    {
        $this->assertSame(1001, Money::fromDecimalString('10.005')->paise);
        $this->assertSame(1000, Money::fromDecimalString('10.004')->paise);
    }

    public function test_it_rejects_nonsense(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimalString('quite a lot');
    }

    public function test_the_classic_float_error_does_not_occur(): void
    {
        // 0.1 + 0.2 !== 0.3 in binary floating point. This is the whole
        // reason Money exists.
        $sum = Money::of('0.10')->plus(Money::of('0.20'));

        $this->assertSame(30, $sum->paise);
        $this->assertSame('0.30', $sum->toDecimalString());
        $this->assertSame(0, $sum->compareTo(Money::of('0.30')));
    }

    public function test_repeated_addition_does_not_drift(): void
    {
        $total = Money::zero();
        for ($i = 0; $i < 10000; $i++) {
            $total = $total->plus(Money::of('0.01'));
        }

        // A float accumulator would be off by now.
        $this->assertSame('100.00', $total->toDecimalString());
    }

    public function test_a_float_input_is_read_through_its_decimal_text(): void
    {
        // 0.29 is not exactly representable; multiplying by 100 gives
        // 28.999... which truncates to 28 paise.
        $this->assertSame(29, Money::of(0.29)->paise);
        $this->assertSame(114, Money::of(1.14)->paise);
    }

    #[DataProvider('halfUpCases')]
    public function test_division_rounds_half_away_from_zero(
        int $numerator,
        int $denominator,
        int $expected,
    ): void {
        $this->assertSame($expected, Money::divideHalfUp($numerator, $denominator));
    }

    /** @return list<array{int, int, int}> */
    public static function halfUpCases(): array
    {
        return [
            'exact' => [400, 2, 200],
            'half rounds up' => [500, 200, 3],       // 2.5 -> 3
            'just under half' => [249, 100, 2],      // 2.49 -> 2
            'just over half' => [251, 100, 3],       // 2.51 -> 3
            'thirds round down' => [100, 3, 33],
            'two thirds round up' => [200, 3, 67],
            'negative half rounds away' => [-500, 200, -3],
            'negative third' => [-100, 3, -33],
        ];
    }

    public function test_it_refuses_to_divide_by_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('100')->dividedBy(0);
    }

    public function test_large_values_stay_exact(): void
    {
        $big = Money::of('99999999.99');
        $this->assertSame(9999999999, $big->paise);
        $this->assertSame('99999999.99', $big->toDecimalString());

        $sum = $big->plus(Money::of('0.01'));
        $this->assertSame('100000000.00', $sum->toDecimalString());
    }

    public function test_clamp_to_zero_never_returns_a_negative(): void
    {
        $this->assertSame(0, Money::of('-500')->clampToZero()->paise);
        $this->assertSame(50000, Money::of('500')->clampToZero()->paise);
    }

    public function test_json_numbers_stay_readable(): void
    {
        // Whole rupees serialise as ints, so the app sees 40000 not 40000.0.
        $this->assertSame(40000, Money::of('40000.00')->toJsonNumber());
        $this->assertSame(65555.56, Money::of('65555.56')->toJsonNumber());
    }
}
