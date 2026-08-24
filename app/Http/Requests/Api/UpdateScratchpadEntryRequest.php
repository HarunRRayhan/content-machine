<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateScratchpadEntryRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'language' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * At least one editable field must actually be sent; a PATCH that names
     * nothing has nothing to do.
     *
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasAny(['title', 'body', 'language'])) {
                $validator->errors()->add(
                    'body',
                    'Send at least one of title, body, or language.',
                );
            }
        });
    }
}
