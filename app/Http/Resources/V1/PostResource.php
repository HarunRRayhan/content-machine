<?php

namespace App\Http\Resources\V1;

use App\Models\Attachment;
use App\Models\Post;
use App\Support\Api\IncludeFields;
use App\Support\Content\PresenceFlags;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Post
 */
class PostResource extends JsonResource
{
    /** @var Post */
    public $resource;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $include = $request->attributes->get('api_include');
        if (! $include instanceof IncludeFields) {
            $include = IncludeFields::full();
        }

        return [
            'id' => $this->id,
            'human_id' => $this->human_id,
            'number' => $this->number,
            'title' => $this->title,
            'language' => $this->language,
            'slug' => $this->slug,
            'body' => $this->when(
                $include->wants('body'),
                $this->body,
            ),
            'captions' => $this->when(
                $include->wants('captions'),
                $this->captions,
            ),
            'has_body' => PresenceFlags::bool(
                $this->resource,
                'has_body',
                fn () => filled($this->body),
            ),
            'has_captions' => PresenceFlags::bool(
                $this->resource,
                'has_captions',
                fn () => ! empty($this->captions),
            ),
            'platforms' => $this->platforms,
            'image_drive_urls' => $this->image_drive_urls,
            'status' => $this->status,
            'publish_state' => $this->publish_state,
            'publish_error' => $this->publish_error,
            'postsyncer' => $this->postsyncer,
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
            $attachments[] = $this->presentAttachment($attachment);
        }

        return $attachments;
    }

    /**
     * @return array{id: int, role: string, filename: string|null, mime: string|null, media_url: string}
     */
    private function presentAttachment(Attachment $attachment): array
    {
        return [
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
}
