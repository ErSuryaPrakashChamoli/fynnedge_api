<?php

namespace App\Services;

use App\Domain\Vault\DocumentStatus;
use App\Domain\Vault\DocumentType;
use App\Domain\Vault\UploadRules;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Storing and removing customer documents.
 *
 * Two rules run through it: the server decides where a file lives, and a
 * stored file is only ever "stored" — nothing here inspects, verifies or
 * forwards it, because nothing in FynnEdge can.
 */
class DocumentVaultService
{
    public const DISK = 'documents';

    /**
     * Stores an upload and records it.
     *
     * The detected type is taken from the file's own bytes. A client that
     * says "application/pdf" over a .php file is describing what it would
     * like FynnEdge to believe, not what it sent.
     */
    public function store(
        User $user,
        UploadedFile $file,
        DocumentType $type,
        ?string $displayName = null,
    ): Document {
        // getMimeType() reads the file, unlike getClientMimeType().
        $mimeType = $file->getMimeType() ?? '';

        if (! in_array($mimeType, UploadRules::mimeTypes(), true)) {
            throw new RuntimeException('unsupported_type');
        }

        if (! UploadRules::extensionMatches($mimeType, $file->getClientOriginalExtension())) {
            throw new RuntimeException('extension_mismatch');
        }

        $size = $file->getSize() ?: 0;

        if ($size <= 0 || $size > UploadRules::MAX_BYTES) {
            throw new RuntimeException('bad_size');
        }

        // Server-generated, from the detected type. The customer's filename
        // never reaches the path, so it cannot traverse out of it, collide
        // with another file, or arrive with an extension a web server would
        // be willing to execute.
        $key = sprintf(
            'u%d/%s.%s',
            $user->getKey(),
            (string) Str::ulid(),
            UploadRules::extensionFor($mimeType),
        );

        $stored = $file->storeAs('', $key, ['disk' => self::DISK]);

        if ($stored === false) {
            throw new RuntimeException('storage_failed');
        }

        return $user->documents()->create([
            'type' => $type->value,
            'display_name' => $this->displayName($displayName, $file),
            'original_filename' => $this->safeOriginalName($file),
            'mime_type' => $mimeType,
            'size_bytes' => $size,
            'storage_disk' => self::DISK,
            'storage_key' => $key,
            // Stored. Not checked, not verified, not sent anywhere.
            'status' => DocumentStatus::Uploaded->value,
        ]);
    }

    /**
     * Removes the file and soft-deletes the record.
     *
     * The bytes go; the row stays, so "you had a document here and you
     * deleted it on this date" remains answerable. How long that record
     * should be kept is a retention decision nobody has made yet — see the
     * limitations note in the module report rather than a policy invented
     * here.
     */
    public function delete(Document $document): void
    {
        if ($document->exists()) {
            $document->disk()->delete($document->storage_key);
        }

        $document->delete();
    }

    /** What the customer sees in their vault. */
    private function displayName(?string $given, UploadedFile $file): string
    {
        $name = trim($given ?? '') !== ''
            ? trim($given)
            : pathinfo($this->safeOriginalName($file), PATHINFO_FILENAME);

        return Str::limit($name === '' ? 'Document' : $name, 190, '');
    }

    /**
     * The original name, stripped of anything that makes it a path.
     *
     * Only ever displayed. Even so, a name carrying "../" is stored as text
     * that reads like an attack in every log it appears in.
     */
    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = ltrim($name, '.');

        return Str::limit($name === '' ? 'document' : $name, 250, '');
    }
}
