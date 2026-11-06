<?php

namespace App\Actions\Posts;

use App\Actions\Ids\ReserveContentIdAction;
use App\Models\Post;
use App\Models\User;
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
    public function handle(Workspace $workspace, array $attributes, ?User $createdBy = null): Post
    {
        return DB::transaction(function () use ($workspace, $attributes, $createdBy) {
            $humanId = isset($attributes['human_id']) ? (string) $attributes['human_id'] : null;

            if ($humanId !== null) {
                $existing = Post::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('human_id', $humanId)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $importedNumber = $this->numberFromHumanId($humanId);
                $number = isset($attributes['number']) ? (int) $attributes['number'] : $importedNumber;

                if ($number !== $importedNumber) {
                    throw ValidationException::withMessages([
                        'number' => 'number must match the number in human_id.',
                    ]);
                }

                if (str_starts_with($humanId, 'P-')) {
                    $this->reserveContentIdAction->advancePostSequence($number);
                }
                $this->reserveContentIdAction->advancePast($workspace, 'post', $number);
            } else {
                // New posts: number = id, human_id = "P-{id}" (display label).
                $newId = $this->reserveContentIdAction->nextPostId($workspace);
                $number = $newId;
                $humanId = "P-{$newId}";
            }

            $status = (string) ($attributes['status'] ?? 'draft');
            if (! in_array($status, Post::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => "Invalid post status [{$status}]."]);
            }

            $post = new Post([
                'workspace_id' => $workspace->id,
                'idea_id' => $attributes['idea_id'] ?? null,
                'number' => $number,
                'human_id' => $humanId,
                'title' => (string) $attributes['title'],
                'language' => $attributes['language'] ?? null,
                'slug' => $attributes['slug'] ?? null,
                'template' => isset($attributes['template'])
                    ? (is_string($attributes['template']) ? strtoupper($attributes['template']) : null)
                    : null,
                'category' => $attributes['category'] ?? 'default',
                'body' => $attributes['body'] ?? null,
                'captions' => $attributes['captions'] ?? null,
                'platforms' => $attributes['platforms'] ?? null,
                'image_drive_urls' => $attributes['image_drive_urls'] ?? null,
                'approval_state' => $attributes['approval_state'] ?? 'approved',
                'approved_at' => ($attributes['approval_state'] ?? 'approved') === 'approved' ? now() : null,
                'approved_by_user_id' => ($attributes['approval_state'] ?? 'approved') === 'approved'
                    ? ($createdBy !== null ? $createdBy->id : Auth::id())
                    : null,
                'status' => $status,
                'created_by_user_id' => $createdBy !== null ? $createdBy->id : Auth::id(),
            ]);

            if (isset($newId)) {
                $post->id = $newId;
            }

            $post->save();

            return $post;
        });
    }

    private function numberFromHumanId(string $humanId): int
    {
        if (preg_match('/^(?:P|BP|EP)-([1-9]\d*)$/', $humanId, $matches) !== 1) {
            throw ValidationException::withMessages([
                'human_id' => 'human_id must be P-N, BP-N, or EP-N with a positive number.',
            ]);
        }

        if ((int) $matches[1] > ReserveContentIdAction::MAX_IMPORT_NUMBER) {
            throw ValidationException::withMessages(['human_id' => 'human_id number is too large.']);
        }

        return (int) $matches[1];
    }
}
