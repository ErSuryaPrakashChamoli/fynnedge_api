<?php

namespace App\Domain\References;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocating a human-readable, sequential reference without losing one.
 *
 * FynnEdge gives customers numbers they read out to people: FE-2026-000042
 * for an application, FE-SUP-2026-000007 for a support request. Sequential
 * and per-year, because 'the one ending forty-two' is something somebody
 * can say down a phone and a UUID is not.
 *
 * The obvious way to allocate one is to read the highest and add one. That
 * is a race, and not a theoretical one: eight customers creating an
 * application at the same moment were measured producing four rows, because
 * four of them read the same highest number and the unique index rejected
 * the losers. Four people were told their application failed.
 *
 * So the index is the allocator. The insert is attempted; a unique
 * violation means somebody took that number in the microseconds since it
 * was read, and the answer is to take the next one rather than to fail.
 * Bounded, because a loop that cannot end is worse than a failed request.
 *
 * Caught as UniqueConstraintViolationException specifically, not by
 * SQLSTATE 23000 — that code covers every integrity constraint, so a NOT
 * NULL violation would be retried to the limit and then reported as a
 * reference problem.
 */
final class SequentialReference
{
    /**
     * Generous, because an attempt only fails when another customer took
     * that exact number in the moment since it was read.
     */
    private const MAX_ATTEMPTS = 25;

    public function __construct(
        /** e.g. 'FE-' or 'FE-SUP-'. The year and counter follow. */
        private readonly string $prefix,
        private readonly string $table,
        private readonly int $digits = 6,
        private readonly string $column = 'reference',
    ) {}

    /**
     * Calls $insert with a candidate reference until one lands.
     *
     * @template T
     *
     * @param  callable(string): T  $insert
     * @return T
     */
    public function allocate(callable $insert): mixed
    {
        $prefix = $this->prefix.now()->year.'-';
        $next = $this->highest($prefix) + 1;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = $prefix.str_pad(
                (string) ($next + $attempt),
                $this->digits,
                '0',
                STR_PAD_LEFT,
            );

            try {
                return $insert($candidate);
            } catch (UniqueConstraintViolationException) {
                // Taken between the read and the write. Take the next.
            }
        }

        throw new RuntimeException(
            "Could not allocate a reference for [{$this->table}]."
        );
    }

    private function highest(string $prefix): int
    {
        /** @var Builder $query */
        $query = DB::table($this->table);

        $last = $query
            ->where($this->column, 'like', $prefix.'%')
            ->orderByDesc($this->column)
            ->value($this->column);

        return $last === null ? 0 : (int) substr((string) $last, strlen($prefix));
    }
}
