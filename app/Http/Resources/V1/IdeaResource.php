<?php

namespace App\Http\Resources\V1;

use App\Models\Idea;
use App\Support\CurrentApiToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON shape of an idea for API clients. Mirrors the dashboard's
 * presentDetail() field-for-field; human_id is the stable external handle
 * (PI-7 / VI-3) API routes address ideas by.
 *
 * @mixin Idea
 */
class IdeaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (! app(CurrentApiToken::class)->can('ideas:read')) {
            return [
                'id' => $this->id,
                'human_id' => $this->human_id,
            ];
        }

        return [
            'id' => $this->id,
            'human_id' => $this->human_id,
            'kind' => $this->kind,
            'title' => $this->title,
            'slug' => $this->slug,
            'score' => $this->score,
            'trend' => $this->trend,
            'rationale' => $this->rationale,
            'body' => $this->body,
            'editorial_type' => $this->editorial_type,
            'details' => $this->details ?? [],
            'status' => $this->status,
            'drop_reason' => $this->drop_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'promoted_to' => $this->presentPromotedEntity(),
        ];
    }

    /**
     * The draft post/video this idea was promoted into. Null for an
     * unpromoted idea.
     *
     * @return array<string, mixed>|null
     */
    private function presentPromotedEntity(): ?array
    {
        $entity = $this->kind === 'video' ? $this->video : $this->post;

        if ($entity === null) {
            return null;
        }

        return [
            'id' => $entity->id,
            'kind' => $this->kind,
            'human_id' => $entity->human_id,
            'title' => $entity->title,
            'status' => $entity->status,
        ];
    }
}
