<?php

namespace App\Http\Controllers\Api;

use App\Domain\Vault\DocumentType;
use App\Domain\Vault\UploadRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Services\DocumentVaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FynnVault — the customer's own documents.
 *
 * Every route resolves through the authenticated user's relation, so another
 * customer's id is simply not found. Files are streamed from a private disk
 * by this controller; there is no URL that reaches one without a token.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentVaultService $vault,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = $request->user()->documents()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return DocumentResource::collection($documents);
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        try {
            $document = $this->vault->store(
                $request->user(),
                $request->file('file'),
                DocumentType::from($request->validated('type')),
                $request->validated('name'),
            );
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return (new DocumentResource($document))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, string $document): DocumentResource
    {
        return new DocumentResource($this->owned($request, $document));
    }

    /**
     * Streams the file to its owner.
     *
     * Not a redirect to storage and not a signed public URL: the bytes go
     * through an authenticated request every time, and no link to them
     * outlives it.
     */
    public function download(Request $request, string $document): StreamedResponse|JsonResponse
    {
        $model = $this->owned($request, $document);

        if (! $model->exists()) {
            return response()->json([
                'message' => 'That document is no longer stored.',
            ], Response::HTTP_NOT_FOUND);
        }

        $inline = $request->boolean('inline');

        return $model->disk()->download(
            $model->storage_key,
            $this->downloadName($model),
            [
                'Content-Type' => $model->mime_type,
                'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                    .'; filename="'.$this->downloadName($model).'"',
                // Never let a proxy or the browser keep a private document.
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function destroy(Request $request, string $document): JsonResponse
    {
        $this->vault->delete($this->owned($request, $document));

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** What FynnVault will accept, so the app can say so before a failure. */
    public function limits(): JsonResponse
    {
        return response()->json([
            'data' => [
                'max_bytes' => UploadRules::MAX_BYTES,
                'max_size_label' => UploadRules::humanSize(UploadRules::MAX_BYTES),
                'mime_types' => UploadRules::mimeTypes(),
                'extensions' => UploadRules::extensions(),
                'types' => array_map(fn (DocumentType $t) => [
                    'id' => $t->value,
                    'label' => $t->label(),
                ], DocumentType::cases()),
            ],
        ]);
    }

    /**
     * Resolved through the customer's own relation.
     *
     * Not route-model binding: that finds the row first and checks ownership
     * second, which answers differently for "not yours" than for "does not
     * exist" and tells the caller which one it was.
     */
    private function owned(Request $request, string $id): Document
    {
        $document = $request->user()->documents()->find($id);

        abort_if(
            $document === null,
            Response::HTTP_NOT_FOUND,
            'That document could not be found.',
        );

        return $document;
    }

    /** A safe filename for the browser, rebuilt from what we control. */
    private function downloadName(Document $document): string
    {
        $extension = UploadRules::extensionFor($document->mime_type) ?? 'bin';
        $base = preg_replace('/[^A-Za-z0-9 ._-]/', '', $document->display_name) ?: 'document';

        return trim($base).'.'.$extension;
    }

    private function failure(RuntimeException $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'unsupported_type', 'extension_mismatch' => response()->json([
                'message' => 'FynnVault takes PDFs and photos (JPG, PNG, HEIC, WebP).',
            ], Response::HTTP_UNPROCESSABLE_ENTITY),

            'bad_size' => response()->json([
                'message' => 'That file is larger than 10 MB.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY),

            'storage_failed' => response()->json([
                'message' => 'That document could not be stored. Please try again.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR),

            default => throw $e,
        };
    }
}
