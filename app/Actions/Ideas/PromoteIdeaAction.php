<?php

namespace App\Actions\Ideas;

use App\Actions\Ids\ReserveContentIdAction;
use App\Models\Idea;
use App\Models\Post;
use App\Models\Video;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Promotes an open idea into a draft post/video shell: reserves the next
 * P-NN/V-NN human id, creates the shell with a snapshot of the idea's
 * title/body, and flips the idea to 'promoted'. Never deletes the idea,
 * same "never delete a row, move it" convention as drop/triage.
 *
 * @throws RuntimeException if the idea isn't open, or isn't a promotable kind
 */
class PromoteIdeaAction
{
    public function __construct(
        private readonly ReserveContentIdAction $reserveContentIdAction,
    ) {}

    public function handle(Idea $idea): Post|Video
    {
        return DB::transaction(function () use ($idea) {
            if ($idea->status !== 'open') {
                throw new RuntimeException('This idea has already been promoted or dropped.');
            }

            $modelClass = match ($idea->kind) {
                'post' => Post::class,
                'video' => Video::class,
                default => throw new RuntimeException("Ideas of kind [{$idea->kind}] cannot be promoted."),
            };

            $workspace = $idea->workspace;
            $contentId = null;
            $postId = null;

            if ($idea->kind === 'post') {
                // Posts: number = id, human_id = "P-{id}" (display label).
                $postId = $this->reserveContentIdAction->nextPostId($workspace);
                $number = $postId;
                $humanId = "P-{$postId}";
            } else {
                $contentId = $this->reserveContentIdAction->handle($workspace, $idea->kind);
                $number = $contentId->number;
                $humanId = $contentId->human_id;
            }

            /** @var Post|Video $entity */
            $entity = new $modelClass([
                'workspace_id' => $workspace->id,
                'idea_id' => $idea->id,
                'number' => $number,
                'human_id' => $humanId,
                'title' => $idea->title,
                'body' => $idea->body,
                'status' => 'draft',
                'created_by_user_id' => Auth::id(),
            ]);

            if ($postId !== null) {
                $entity->id = $postId;
            }

            $entity->save();

            $contentId?->forceFill([
                'entity_type' => $entity->getMorphClass(),
                'entity_id' => $entity->id,
            ])->save();

            $from = $idea->status;
            $idea->forceFill(['status' => 'promoted'])->save();
            $idea->recordStatusTransition($from, 'promoted');

            return $entity;
        });
    }
}
