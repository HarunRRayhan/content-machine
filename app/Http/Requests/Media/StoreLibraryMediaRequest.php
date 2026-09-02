<?php

namespace App\Http\Requests\Media;

use App\Support\Media\MediaLibraryTab;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLibraryMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tab = MediaLibraryTab::tryFrom((string) $this->input('tab'));

        return [
            'tab' => ['required', 'string', Rule::enum(MediaLibraryTab::class)],
            'file' => ['required', 'file', ...$this->fileRules($tab)],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return list<string>
     */
    private function fileRules(?MediaLibraryTab $tab): array
    {
        return match ($tab) {
            MediaLibraryTab::Images => [
                'mimes:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'max:20480',
            ],
            MediaLibraryTab::Gifs => [
                'mimes:gif',
                'mimetypes:image/gif',
                'max:20480',
            ],
            MediaLibraryTab::Videos => [
                'mimes:mp4,webm,mov',
                'mimetypes:video/mp4,video/webm,video/quicktime',
                'max:102400',
            ],
            default => ['max:20480'],
        };
    }
}
