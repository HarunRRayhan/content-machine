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
            $contentId = $this->reserveContentIdAction->handle($workspace, $idea->kind);

            /** @var Post|Video $entity */
            $entity = $modelClass::create([
                'workspace_id' => $workspace->id,
                'idea_id' => $idea->id,
                'number' => $contentId->number,
                'human_id' => $contentId->human_id,
                'title' => $idea->title,
                'body' => $idea->body,
                'status' => 'draft',
                'created_by_user_id' => Auth::id(),
            ]);

            $contentId->forceFill([
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
