<?php

namespace App\Http\Requests\Videos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePresentationCueRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'step' => ['required', 'integer', 'min:0'],
            'current_cue' => ['required', 'string', 'max:5000'],
            'cue' => ['required', 'string', 'max:5000'],
        ];
    }
}
