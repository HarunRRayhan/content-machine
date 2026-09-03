<?php

namespace App\Http\Resources\V1;

use App\Models\Video;
use App\Support\Api\IncludeFields;
use App\Support\Content\PresenceFlags;
use App\Support\Content\PresentationManifest;
use App\Support\CurrentApiToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Video
 */
class VideoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (! app(CurrentApiToken::class)->can('videos:read')) {
            return [
                'id' => $this->id,
                'human_id' => $this->human_id,
            ];
        }

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
            'script_markdown' => $this->when(
                $include->wants('script_markdown'),
                $this->script_markdown,
            ),
            'captions' => $this->when(
                $include->wants('captions'),
                $this->captions,
            ),
            'deck_manifest' => $this->when(
                $include->wants('deck_manifest'),
                $this->deck_manifest,
            ),
            'has_script' => PresenceFlags::bool(
                $this->resource,
                'has_script',
                fn () => filled($this->script_markdown),
            ),
            'has_captions' => PresenceFlags::bool(
                $this->resource,
                'has_captions',
                fn () => ! empty($this->captions),
            ),
            'has_deck' => PresenceFlags::bool(
                $this->resource,
                'has_deck',
                fn () => PresentationManifest::isUsable($this->deck_manifest),
            ),
            'video_drive_url' => $this->video_drive_url,
            'cover_drive_url' => $this->cover_drive_url,
            'status' => $this->status,
            'publish_state' => $this->publish_state,
            'publish_error' => $this->publish_error === null
                ? null
                : 'Publishing failed. Inspect the dashboard for details.',
            'postsyncer' => $this->postsyncer,
            'idea_id' => $this->idea_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
