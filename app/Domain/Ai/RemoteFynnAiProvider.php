<?php

namespace App\Domain\Ai;

/**
 * A provider that answers from another service, which can be down.
 *
 * isAvailable() says whether one is configured. This says whether it is
 * answering right now — a question only a provider on the other end of a
 * network connection has to be asked.
 */
interface RemoteFynnAiProvider extends FynnAiProvider
{
    public function isReachable(): bool;
}
