<?php

namespace App\Http\Requests;

use App\Domain\Consent\ConsentSource;
use App\Domain\Consent\ConsentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * No customer identifier and no version: whose consent this is comes from
     * the token, and which version it is recorded under comes from the
     * catalogue. A client that could choose either could record a consent the
     * customer never gave, under wording they never saw.
     *
     * The source is one of FynnEdge's own surfaces and nothing else. It is
     * part of the append-only record of how permission was obtained, so free
     * text here would let a caller assert a provenance that never happened.
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(ConsentType::ids())],
            'source' => ['sometimes', Rule::in(ConsentSource::ids())],
        ];
    }
}
