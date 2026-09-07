<?php

namespace App\Http\Requests;

use App\Domain\Vault\DocumentType;
use App\Domain\Vault\UploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `mimetypes` checks the file itself; `mimes` checks the extension. Both
     * are here because either alone can be satisfied by a file pretending to
     * be something it is not.
     *
     * No customer identifier: whose vault this lands in comes from the token.
     */
    public function rules(): array
    {
        $maxKilobytes = (int) (UploadRules::MAX_BYTES / 1024);

        return [
            'file' => [
                'required',
                'file',
                'max:'.$maxKilobytes,
                'mimetypes:'.implode(',', UploadRules::mimeTypes()),
                'mimes:'.implode(',', UploadRules::extensions()),
            ],
            'type' => ['required', Rule::in(DocumentType::ids())],
            'name' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimetypes' => 'FynnVault takes PDFs and photos (JPG, PNG, HEIC, WebP).',
            'file.mimes' => 'FynnVault takes PDFs and photos (JPG, PNG, HEIC, WebP).',
            'file.max' => 'That file is larger than 10 MB.',
            'file.required' => 'Choose a file to add.',
        ];
    }
}
