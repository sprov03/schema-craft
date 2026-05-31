<?php

namespace SchemaCraft\Tests\Fixtures\Api\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Drives the update() body params. All optional (nullable) so the generated
 * update() method emits `$field = null` defaults for each.
 */
class UpdateCatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string'],
            'price' => ['nullable', 'numeric'],
        ];
    }
}
