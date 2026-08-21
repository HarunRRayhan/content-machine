<?php

namespace App\Http\Requests\AiProviders;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddAiProviderCredentialModelsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'models' => ['required', 'array', 'min:1'],
            'models.*' => ['string', 'max:255', 'distinct'],
            'purpose' => ['required', Rule::in(['default', 'vision'])],
        ];
    }
}
