<?php

namespace App\Http\Requests\Scratchpad;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TriageScratchpadEntryRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'target' => ['required', Rule::in(['post_idea', 'video_idea', 'drop'])],
            'title' => [
                Rule::requiredIf(fn () => $this->input('target') !== 'drop'),
                'nullable', 'string', 'max:255',
            ],
            'score' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'trend' => ['nullable', Rule::in(['evergreen', 'seasonal'])],
            'rationale' => ['nullable', 'string', 'max:2000'],
            'drop_reason' => [
                Rule::requiredIf(fn () => $this->input('target') === 'drop'),
                'nullable', 'string', 'max:2000',
            ],
        ];
    }
}
