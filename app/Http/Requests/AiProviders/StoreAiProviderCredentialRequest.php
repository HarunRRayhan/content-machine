<?php

namespace App\Http\Requests\AiProviders;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiProviderCredentialRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'provider' => ['required', Rule::in(['anthropic', 'openai'])],
            'base_url' => ['nullable', 'url:https', 'max:500'],
            'api_key' => ['required', 'string', 'max:500'],
        ];
    }
}
