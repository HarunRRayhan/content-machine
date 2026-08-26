<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'human_id' => $this->human_id,
            'number' => $this->number,
            'title' => $this->title,
            'language' => $this->language,
            'slug' => $this->slug,
            'body' => $this->body,
            'captions' => $this->captions,
            'platforms' => $this->platforms,
            'status' => $this->status,
            'idea_id' => $this->idea_id,
            'attachments' => $this->relationLoaded('attachments')
                ? $this->presentAttachments()
                : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{id: int, role: string, filename: string|null, mime: string|null, media_url: string}>
     */
    private function presentAttachments(): array
    {
        $attachments = [];

        foreach ($this->attachments->sortBy('position') as $attachment) {
            $attachments[] = [
                'id' => $attachment->id,
                'role' => $attachment->role,
                'filename' => $attachment->mediaAsset?->original_filename,
                'mime' => $attachment->mediaAsset?->mime,
                'media_url' => route('api.v1.posts.media', [
                    'human_id' => $this->resource->human_id,
                    'mediaAsset' => $attachment->media_asset_id,
                ]),
            ];
        }

        return $attachments;
    }
}
