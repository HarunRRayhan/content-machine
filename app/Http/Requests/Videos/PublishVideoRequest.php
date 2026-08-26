<?php

namespace App\Http\Requests\Videos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PublishVideoRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'when' => ['nullable', 'string', 'max:64'],
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'max:64'],
            'confirm_ask' => ['nullable', 'boolean'],
        ];
    }
}
