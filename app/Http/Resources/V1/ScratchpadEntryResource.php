<?php

namespace App\Http\Resources\V1;

use App\Models\Attachment;
use App\Models\ScratchpadEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON shape of a scratchpad entry for API clients. Mirrors the
 * dashboard's presentDetail() field-for-field so a consumer reading either
 * surface sees the same entry; the one deliberate difference is media_url,
 * which points at the authenticated API media endpoint rather than the
 * session-guarded dashboard one.
 *
 * @mixin ScratchpadEntry
 */
class ScratchpadEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'kind' => $this->kind,
            'status' => $this->status,
            'source' => $this->source,
            'language' => $this->language,
            'title' => $this->title,
            'body' => $this->body,
            'captured_at' => $this->captured_at->toIso8601String(),
            'drop_reason' => $this->drop_reason,
            'attachments' => $this->presentAttachments(),
            'link' => $this->presentLink(),
            'transcription' => $this->presentTranscription(),
            'idea' => $this->presentTriagedIdea(),
        ];
    }

    /**
     * The entry's attached media (a photo's image, a voice memo's audio),
     * pointing at the token-authenticated GET .../scratchpad/{public_id}/media/{id}
     * route rather than any public URL. Empty for a text entry.
     *
     * @return array<int, array<string, mixed>>
     */
    private function presentAttachments(): array
    {
        return $this->attachments
            ->sortBy('position')
            ->values()
            ->map(fn (Attachment $attachment) => [
                'id' => $attachment->id,
                'role' => $attachment->role,
                'mime' => $attachment->mediaAsset->mime,
                'media_url' => route('api.v1.scratchpad.media', [
                    'public_id' => $this->resource->public_id,
                    'mediaAsset' => $attachment->media_asset_id,
                ]),
            ])
            ->all();
    }

    /**
     * A link entry's original URL and how far resolution got, matching the
     * dashboard's presentation exactly. Null for every other kind.
     *
     * @return array<string, mixed>|null
     */
    private function presentLink(): ?array
    {
        if ($this->kind !== 'link') {
            return null;
        }

        return [
            'url' => $this->meta['url'] ?? null,
            'resolved_via' => $this->meta['resolved_via'] ?? null,
            'thumbnail_url' => $this->meta['thumbnail_url'] ?? null,
            'summarized' => isset($this->meta['summarized_at']),
        ];
    }

    /**
     * A voice entry's transcription at whatever stage it's at. Null for
     * every other kind.
     *
     * @return array<string, mixed>|null
     */
    private function presentTranscription(): ?array
    {
        $transcription = $this->transcriptions->first();

        if ($transcription === null) {
            return null;
        }

        return [
            'status' => $transcription->status,
            'text' => $transcription->text,
            'language' => $transcription->language,
            'error_message' => $transcription->error_message,
        ];
    }

    /**
     * The idea this entry was triaged into. Null for an untriaged or
     * dropped entry.
     *
     * @return array<string, mixed>|null
     */
    private function presentTriagedIdea(): ?array
    {
        $idea = $this->ideas()->first();

        if ($idea === null) {
            return null;
        }

        return [
            'id' => $idea->id,
            'human_id' => $idea->human_id,
            'kind' => $idea->kind,
            'title' => $idea->title,
        ];
    }
}
