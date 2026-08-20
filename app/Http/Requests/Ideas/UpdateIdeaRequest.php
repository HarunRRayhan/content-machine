<?php

namespace App\Http\Requests\Ideas;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIdeaRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'score' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'trend' => ['nullable', Rule::in(['evergreen', 'seasonal'])],
            'rationale' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string', 'max:20000'],
        ];
    }
}
