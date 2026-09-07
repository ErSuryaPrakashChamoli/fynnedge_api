<?php

namespace App\Domain\Consent;

enum ConsentStatus: string
{
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Granted => 'Granted',
            self::Withdrawn => 'Withdrawn',
        };
    }
}
