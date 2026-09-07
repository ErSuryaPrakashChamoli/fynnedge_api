<?php

namespace App\Domain\Vault;

/**
 * What has happened to a document.
 *
 * Two states, because two things can happen: the bytes were stored, or they
 * were not. There is no verification, no processing and no rejection in this
 * product, so there are no statuses claiming otherwise — a document shown as
 * verified because an upload succeeded is a lie the customer would rely on.
 */
enum DocumentStatus: string
{
    case Uploaded = 'uploaded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Stored',
            self::Failed => 'Upload failed',
        };
    }
}
