<?php

namespace App\Models;

use App\Domain\Vault\DocumentStatus;
use App\Domain\Vault\DocumentType;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * One document in a customer's vault.
 *
 * The row holds where the bytes are; it never holds the bytes. Deleting a
 * document removes the file and soft-deletes the row, so the vault forgets
 * the contents while the record that something was stored and then removed
 * survives.
 */
class Document extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'display_name',
        'original_filename',
        'mime_type',
        'size_bytes',
        'storage_disk',
        'storage_key',
        'status',
    ];

    /**
     * The storage location never leaves the server. Hiding it here means an
     * accidental `toArray()` anywhere cannot publish it.
     */
    protected $hidden = ['storage_disk', 'storage_key'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function type(): DocumentType
    {
        return DocumentType::from($this->type);
    }

    public function status(): DocumentStatus
    {
        return DocumentStatus::from($this->status);
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->storage_disk);
    }

    public function exists(): bool
    {
        return $this->disk()->exists($this->storage_key);
    }
}
