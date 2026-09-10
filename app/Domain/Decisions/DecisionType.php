<?php

namespace App\Domain\Decisions;

/**
 * A financial decision the customer actually explored.
 *
 * Four cases, and the reason there are not eight is the whole shape of this
 * module. A decision context is something the customer *did* — filled in a
 * form, picked products, started an application. It is not a screen they
 * opened.
 *
 * So there is no `product_detail_review` and no `fynntrust_review`. Both
 * would be records of a GET, which is page-view tracking however it is
 * labelled; and FynnTrust and FynnMirror have no API endpoint at all, so
 * recording them would mean inventing a tracking endpoint to feed a history
 * nobody asked for. Opening a screen is not a decision, and a product that
 * treats it as one is telling the customer something untrue about
 * themselves.
 *
 * Two of these are recorded by this module. The other two are read from
 * `loan_applications`, which already holds a complete, immutable snapshot of
 * what the customer was shown — the amount, tenure, rate, EMI, fees and the
 * catalogue version that produced them. Copying that into a second table
 * would create two records that could disagree.
 */
enum DecisionType: string
{
    /** The customer ran a FynnTwin simulation. */
    case Simulation = 'simulation';

    /** The customer put products side by side. */
    case Comparison = 'comparison';

    /** Read from the application itself. */
    case ApplicationStarted = 'application_started';

    /** Read from the application itself. */
    case ApplicationSubmitted = 'application_submitted';

    /** Whether this module writes it, or reads it from somewhere else. */
    public function isRecorded(): bool
    {
        return in_array($this, [self::Simulation, self::Comparison], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Simulation => 'You ran a simulation',
            self::Comparison => 'You compared options',
            self::ApplicationStarted => 'You started an application',
            self::ApplicationSubmitted => 'You submitted an application',
        };
    }

    /**
     * One line saying what the customer did, and no more.
     *
     * None of these says what the customer wanted, preferred, chose or
     * intended. Running a simulation is running a simulation.
     */
    public function description(): string
    {
        return match ($this) {
            self::Simulation => 'A what-if, worked out from the figures you '
                .'entered. Nothing was applied for.',
            self::Comparison => 'You looked at these side by side. FynnEdge '
                .'picked no winner.',
            self::ApplicationStarted => 'An application was created with the '
                .'terms shown at the time.',
            self::ApplicationSubmitted => 'You asked FynnEdge to send this to '
                .'the provider.',
        };
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
