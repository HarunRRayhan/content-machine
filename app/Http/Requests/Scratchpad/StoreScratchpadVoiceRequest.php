<?php

namespace App\Http\Requests\Scratchpad;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScratchpadVoiceRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `mimetypes:` (checked against PHP's content-sniffed MIME type, via
     * finfo/Symfony's MimeTypes::guessMimeType()) rather than `mimes:`
     * (checked against a guessed file extension): a real `audio/webm` blob
     * has no filename extension at all, and even when one is added,
     * Symfony's mime map guesses `audio/webm` back to `.weba`, not `.webm`,
     * so an extension-based `mimes:webm` rule would reject a real browser
     * recording.
     *
     * `video/webm` and `video/mp4` are deliberately in this *audio*
     * whitelist. Verified with real ffmpeg-encoded audio-only fixtures
     * (`ffmpeg -c:a libopus … out.webm`, and a fragmented MP4 matching what
     * MediaRecorder actually streams for `audio/mp4`): PHP's content-based
     * mime sniffing cannot tell an audio-only WebM or fragmented-MP4
     * container from a video one just from its container bytes, so a
     * genuine browser voice recording content-sniffs as `video/webm` or
     * `video/mp4`, never `audio/webm`/`audio/mp4`. Without these, every real
     * recording from Chrome/Firefox (`audio/webm;codecs=opus`) or a
     * fragmented-MP4 fallback would fail here. This only affects
     * validation; the value actually stored in media_assets.mime is the
     * browser-declared type, not the sniffed one (see ResolvesMediaAsset).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'audio' => [
                'required',
                'file',
                'max:25600',
                'mimetypes:audio/webm,video/webm,audio/ogg,audio/mp4,video/mp4,audio/x-m4a,audio/mpeg,audio/wav,audio/x-wav',
            ],
            'language' => ['nullable', Rule::in(['bn', 'en'])],
        ];
    }
}
