<?php

namespace App\Http\Requests\Videos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVideoRequest extends FormRequest
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
            'body' => ['nullable', 'string', 'max:20000'],
            'video_drive_url' => ['nullable', 'string', 'url', 'max:2048'],
            'cover_drive_url' => ['nullable', 'string', 'url', 'max:2048'],
        ];
    }
}
