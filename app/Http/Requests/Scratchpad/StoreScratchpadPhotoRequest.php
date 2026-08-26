<?php

namespace App\Http\Requests\Scratchpad;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScratchpadPhotoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'max:10240', 'mimes:jpeg,png,webp,gif,heic'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'language' => ['nullable', Rule::in(['bn', 'en'])],
        ];
    }
}
