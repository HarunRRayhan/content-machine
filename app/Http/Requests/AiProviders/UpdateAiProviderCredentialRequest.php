<?php

namespace App\Http\Requests\AiProviders;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAiProviderCredentialRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * api_key is deliberately optional here (unlike the store request): the
     * key is never sent back to the client once saved (see
     * AiProviderCredentialsController::presentCredential()), so an edit
     * that doesn't touch the key must be able to submit without it. An
     * empty value leaves the stored key untouched, see
     * UpdateAiProviderCredentialData::fromRequest().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'base_url' => ['nullable', 'url', 'max:500'],
            'model' => ['nullable', 'string', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ];
    }
}
