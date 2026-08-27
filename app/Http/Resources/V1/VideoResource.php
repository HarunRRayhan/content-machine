<?php

namespace App\Http\Resources\V1;

use App\Models\Video;
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
        return [
            'id' => $this->id,
            'human_id' => $this->human_id,
            'number' => $this->number,
            'title' => $this->title,
            'language' => $this->language,
            'slug' => $this->slug,
            'body' => $this->body,
            'script_markdown' => $this->script_markdown,
            'captions' => $this->captions,
            'deck_manifest' => $this->deck_manifest,
            'video_drive_url' => $this->video_drive_url,
            'cover_drive_url' => $this->cover_drive_url,
            'status' => $this->status,
            'publish_state' => $this->publish_state,
            'publish_error' => $this->publish_error,
            'postsyncer' => $this->postsyncer,
            'idea_id' => $this->idea_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
