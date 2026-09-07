<?php

namespace App\Domain\Vault;

/**
 * What FynnVault will accept, in one place so the validator, the storage key
 * and the tests cannot disagree about it.
 */
final class UploadRules
{
    /** 10 MB. Comfortably more than a scanned statement, far less than a video. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Types a financial document arrives as. Nothing here can be executed by
     * a web server, which is the point.
     *
     * @return array<string, list<string>> mime type => permitted extensions
     */
    public static function allowed(): array
    {
        return [
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/heic' => ['heic'],
            'image/webp' => ['webp'],
        ];
    }

    /** @return list<string> */
    public static function mimeTypes(): array
    {
        return array_keys(self::allowed());
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::allowed()))));
    }

    /**
     * The extension FynnEdge will store the file under, chosen from the type
     * the server detected — never from the name the client sent.
     */
    public static function extensionFor(string $mimeType): ?string
    {
        return self::allowed()[$mimeType][0] ?? null;
    }

    /** True when the detected type and the file's extension tell one story. */
    public static function extensionMatches(string $mimeType, ?string $extension): bool
    {
        if ($extension === null) {
            return false;
        }

        return in_array(
            mb_strtolower($extension),
            self::allowed()[$mimeType] ?? [],
            true,
        );
    }

    public static function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : max(1, (int) round($bytes / 1024)).' KB';
    }
}
