<?php

namespace App\Actions\Posts;

use App\Actions\Ids\ReserveContentIdAction;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePostAction
{
    public function __construct(
        private readonly ReserveContentIdAction $reserveContentIdAction,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Workspace $workspace, array $attributes): Post
    {
        return DB::transaction(function () use ($workspace, $attributes) {
            $humanId = isset($attributes['human_id']) ? (string) $attributes['human_id'] : null;

            if ($humanId !== null) {
                $existing = Post::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('human_id', $humanId)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $number = isset($attributes['number'])
                    ? (int) $attributes['number']
                    : $this->numberFromHumanId($humanId);
            } else {
                $contentId = $this->reserveContentIdAction->handle($workspace, 'post');
                $humanId = $contentId->human_id;
                $number = $contentId->number;
            }

            $status = (string) ($attributes['status'] ?? 'draft');
            if (! in_array($status, Post::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => "Invalid post status [{$status}]."]);
            }

            $post = Post::create([
                'workspace_id' => $workspace->id,
                'idea_id' => $attributes['idea_id'] ?? null,
                'number' => $number,
                'human_id' => $humanId,
                'title' => (string) $attributes['title'],
                'language' => $attributes['language'] ?? null,
                'slug' => $attributes['slug'] ?? null,
                'body' => $attributes['body'] ?? null,
                'captions' => $attributes['captions'] ?? null,
                'platforms' => $attributes['platforms'] ?? null,
                'image_drive_urls' => $attributes['image_drive_urls'] ?? null,
                'status' => $status,
                'created_by_user_id' => Auth::id(),
            ]);

            if (isset($contentId)) {
                $contentId->forceFill([
                    'entity_type' => $post->getMorphClass(),
                    'entity_id' => $post->id,
                ])->save();
            }

            return $post;
        });
    }

    private function numberFromHumanId(string $humanId): int
    {
        if (preg_match('/(\d+)$/', $humanId, $matches) !== 1) {
            throw ValidationException::withMessages([
                'human_id' => 'human_id must end in a number when number is omitted.',
            ]);
        }

        return (int) $matches[1];
    }
}
