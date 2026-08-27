<?php

namespace App\Http\Requests\Posts;

use App\Data\Posts\UpdatePostData;
use App\Models\Post;
use App\Support\GoogleDrive\GoogleDriveLinkChecker;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePostRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(Post::STATUSES)],
            'image_drive_urls' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('image_drive_urls')) {
                    return;
                }

                $urls = UpdatePostData::parseDriveUrls($this->input('image_drive_urls')) ?? [];
                $checker = app(GoogleDriveLinkChecker::class);

                foreach ($urls as $url) {
                    $result = $checker->check($url);

                    if (! $result->ok) {
                        $validator->errors()->add('image_drive_urls', $result->message);
                    }
                }
            },
        ];
    }
}
