<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchPexelsPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'orientation' => ['sometimes', 'string', Rule::in(['portrait', 'landscape', 'square'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:80'],
        ];
    }
}
