<?php

namespace App\Http\Requests\Posts;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostMotionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // 25 MB per slide. The base image's PHP upload/post max and nginx body limit are 100M.
            'file' => ['required', 'file', 'max:25600', 'mimetypes:video/mp4'],
            'filename' => ['nullable', 'string', 'max:255'],
            // Instagram only: SocialReins publishes these as its Motion Carousel (PostSyncer allowed one video per post).
            'platform' => ['required', 'string', Rule::in(['instagram'])],
            'language' => ['required', 'string', Rule::in(['bn', 'en'])],
            // attachments.position is a smallint shared with image/document rows: keep it far below the limit.
            'order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
