<?php

namespace App\Domain\Ai;

/**
 * One answer from whatever is behind FynnAI.
 */
final class AiReply
{
    /**
     * @param  list<string>  $suggestions
     * @param  list<array{label: string, route: string}>  $actions
     */
    public function __construct(
        public readonly string $text,
        public readonly array $suggestions = [],
        public readonly array $actions = [],
        /**
         * The figures the answer is built on, so a screen or a test can
         * check that what was said matches what was computed.
         *
         * @var array<string, mixed>
         */
        public readonly array $groundedIn = [],
    ) {}
}
