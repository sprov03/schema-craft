<?php

namespace SchemaCraft\Tests\Fixtures\Api\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Drives the create() body params for the SDK. EndpointEnricher::buildParametersFromRules
 * turns these rules into the canonical parameter shape the SDK generator consumes, so the
 * generated create() signature mirrors these fields and their inferred types:
 *   integer -> int, boolean -> bool, numeric -> float, array -> array, else string.
 * Dot-notation rules (price_history.*) mark `price_history` as a nested-relationship array.
 */
class CreateCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            'subtitle' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
            'price' => ['required', 'numeric'],
            'tier' => ['required', 'integer'],
            'attributes_json' => ['nullable', 'array'],
            'price_history' => ['nullable', 'array'],
            'price_history.*.amount' => ['numeric'],
        ];
    }
}
