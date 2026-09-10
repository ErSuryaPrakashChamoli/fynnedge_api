<?php

namespace App\Domain;

use InvalidArgumentException;

/**
 * An exact rupee amount, held as an integer number of paise.
 *
 * Money is never a float in FynnEdge. 0.1 + 0.2 is not 0.3 in binary floating
 * point, and a customer who sees their surplus drift by a paisa stops trusting
 * every other number on the screen. Integers have no such failure mode, and
 * they give the Laravel and Flutter engines identical semantics without
 * depending on bcmath (not available here) or a decimal package on either side.
 *
 * Rounding happens only where this class says it does: division rounds half
 * away from zero, to the nearest paise. Everything else is exact.
 */
final class Money
{
    private function __construct(public readonly int $paise) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromPaise(int $paise): self
    {
        return new self($paise);
    }

    /**
     * Parses a decimal string such as "100000.55" without ever touching a
     * float. Accepts what a decimal(14,2) column and a JSON number both
     * produce.
     */
    public static function fromDecimalString(string $value): self
    {
        $value = trim($value);
        if ($value === '') {
            return self::zero();
        }

        if (! preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $value, $m)) {
            throw new InvalidArgumentException("Not a decimal amount: {$value}");
        }

        [, $sign, $whole, $fraction] = $m + [3 => ''];

        // Pad or truncate to exactly two decimal places, rounding half up on
        // the third digit so a stray "10.005" cannot silently drop to 10.00.
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
        $paise = ((int) $whole) * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $paise++;
        }

        return new self($sign === '-' ? -$paise : $paise);
    }

    /**
     * Accepts whatever Eloquent or a request hands over: a decimal string, an
     * int, or a float that originated as a JSON number.
     */
    public static function of(int|float|string|null $value): self
    {
        if ($value === null) {
            return self::zero();
        }
        if (is_int($value)) {
            return new self($value * 100);
        }
        if (is_float($value)) {
            // The float came off the wire; convert through its decimal text
            // rather than multiplying, so 0.29 does not become 28 paise.
            return self::fromDecimalString(number_format($value, 2, '.', ''));
        }

        return self::fromDecimalString($value);
    }

    public function plus(self $other): self
    {
        return new self($this->paise + $other->paise);
    }

    public function minus(self $other): self
    {
        return new self($this->paise - $other->paise);
    }

    public function times(int $factor): self
    {
        return new self($this->paise * $factor);
    }

    /** Rounds half away from zero, to the nearest paise. */
    public function dividedBy(int $divisor): self
    {
        return new self(self::divideHalfUp($this->paise, $divisor));
    }

    public function isZero(): bool
    {
        return $this->paise === 0;
    }

    public function isPositive(): bool
    {
        return $this->paise > 0;
    }

    public function isNegative(): bool
    {
        return $this->paise < 0;
    }

    public function compareTo(self $other): int
    {
        return $this->paise <=> $other->paise;
    }

    public function max(self $other): self
    {
        return $this->paise >= $other->paise ? $this : $other;
    }

    /** Never below zero — used where a negative shortfall is meaningless. */
    public function clampToZero(): self
    {
        return $this->paise < 0 ? self::zero() : $this;
    }

    /** "100000.55" — the exact value, suitable for a decimal column. */
    public function toDecimalString(): string
    {
        $sign = $this->paise < 0 ? '-' : '';
        $abs = abs($this->paise);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A JSON number for the API. This is the one place a float appears, at
     * the serialisation boundary, after all arithmetic is finished.
     */
    public function toJsonNumber(): int|float
    {
        return $this->paise % 100 === 0
            ? intdiv($this->paise, 100)
            : (float) $this->toDecimalString();
    }

    /**
     * Integer division rounding half away from zero.
     *
     * Written out rather than using round(), which goes through a float and
     * would reintroduce exactly the imprecision this class exists to avoid.
     */
    /**
     * Indian digit grouping — ₹3,00,000 rather than ₹300,000.
     *
     * Defined once. A figure quoted by the matching engine and the same
     * figure quoted by the assistant must not be grouped two different ways
     * on two screens of the same product.
     */
    public function toIndianString(): string
    {
        $whole = (string) abs(self::divideHalfUp($this->paise, 100));

        if (strlen($whole) <= 3) {
            return '₹'.$whole;
        }

        $last3 = substr($whole, -3);
        $rest = substr($whole, 0, -3);
        $parts = [];

        while (strlen($rest) > 2) {
            array_unshift($parts, substr($rest, -2));
            $rest = substr($rest, 0, -2);
        }

        if ($rest !== '') {
            array_unshift($parts, $rest);
        }

        return '₹'.implode(',', $parts).','.$last3;
    }

    /**
     * The same grouping, with the sign in front of the symbol.
     *
     * toIndianString() takes the absolute value, and that is right where it
     * is used: "you would be spending ₹8,000 more than you earn" gets its
     * direction from the sentence around it, and a minus there would say
     * the opposite of what is meant.
     *
     * A figure standing on its own has no sentence to lean on. A recorded
     * surplus of -₹8,000 printed as ₹8,000 is not a formatting nicety, it
     * is the wrong number — so anything that shows a figure by itself uses
     * this one. The sign leads the symbol, exactly as Fmt.money does in the
     * app: a negative amount is a shortfall, not a currency called minus
     * rupees.
     */
    public function toSignedIndianString(): string
    {
        $grouped = $this->toIndianString();

        return $this->isNegative() ? '-'.$grouped : $grouped;
    }

    public static function divideHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            throw new InvalidArgumentException('Division by zero');
        }

        $negative = ($numerator < 0) !== ($denominator < 0);
        $n = abs($numerator);
        $d = abs($denominator);
        $result = intdiv(2 * $n + $d, 2 * $d);

        return $negative ? -$result : $result;
    }
}
