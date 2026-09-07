<?php

namespace App\Http\Resources;

use App\Domain\Vault\UploadRules;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Document $resource
 *
 * Note what is absent: storage_disk and storage_key. Where a file lives is
 * FynnEdge's business, and a customer API that publishes it is handing out
 * addresses to try.
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'id' => (string) $d->id,
            'type' => $d->type->value ?? $d->type,
            'type_label' => $d->type()->label(),

            'name' => $d->display_name,
            'original_filename' => $d->original_filename,

            'mime_type' => $d->mime_type,
            'size_bytes' => $d->size_bytes,
            'size_label' => UploadRules::humanSize($d->size_bytes),

            'status' => $d->status,
            'status_label' => $d->status()->label(),

            // Stored, and that is all that has happened to it. Said in the
            // payload so no screen has to remember to say it.
            'is_verified' => false,
            'is_shared' => false,

            'uploaded_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }
}
