<?php

namespace App\Actions\Scratchpad;

use App\Actions\Ids\ReserveContentIdAction;
use App\Data\Scratchpad\TriageScratchpadEntryData;
use App\Models\Idea;
use App\Models\ScratchpadEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Routes a scratchpad entry into a post idea, a video idea, or a drop.
 *
 * Returns the triaged ScratchpadEntry, not the Idea it may have created:
 * "triage" is fundamentally about what happened to the capture, and the
 * entry is what every caller already holds a reference to. A caller that
 * needs the new Idea can follow the `ideas()` relation on the returned
 * entry (at most one, since an entry can only be triaged once).
 *
 * @throws RuntimeException if the entry has already been triaged or dropped
 */
class TriageScratchpadEntryAction
{
    public function __construct(
        private readonly ReserveContentIdAction $reserveContentIdAction,
    ) {}

    public function handle(ScratchpadEntry $entry, User $actor, TriageScratchpadEntryData $data): ScratchpadEntry
    {
        return DB::transaction(function () use ($entry, $actor, $data) {
            if ($entry->status !== 'new') {
                throw new RuntimeException('This entry has already been triaged.');
            }

            if ($data->target === 'drop') {
                $this->drop($entry, $actor, $data);
            } else {
                $this->file($entry, $actor, $data);
            }

            return $entry;
        });
    }

    private function drop(ScratchpadEntry $entry, User $actor, TriageScratchpadEntryData $data): void
    {
        $from = $entry->status;

        $entry->forceFill([
            'status' => 'dropped',
            'drop_reason' => $data->dropReason,
            'triaged_at' => now(),
            'triaged_by_user_id' => $actor->id,
        ])->save();

        $entry->recordStatusTransition($from, 'dropped', $data->dropReason);
    }

    private function file(ScratchpadEntry $entry, User $actor, TriageScratchpadEntryData $data): void
    {
        $workspace = $entry->workspace;

        // $data->target ('post_idea'/'video_idea') doubles as the
        // content_ids "kind" reserved here. ideas.kind uses a different,
        // narrower vocabulary (post/video/feature, per its CHECK
        // constraint), so it's derived by stripping the "_idea" suffix
        // rather than reusing $data->target directly.
        $contentId = $this->reserveContentIdAction->handle($workspace, $data->target);
        $kind = Str::before($data->target, '_idea');

        /** @var string $title */
        $title = $data->title;

        $idea = Idea::create([
            'workspace_id' => $workspace->id,
            'kind' => $kind,
            'number' => $contentId->number,
            'human_id' => $contentId->human_id,
            'title' => $title,
            'slug' => $this->uniqueSlug($title, $workspace->id, $kind),
            'score' => $data->score,
            'trend' => $data->trend,
            'rationale' => $data->rationale,
            'body' => $entry->body,
            'status' => 'open',
            'scratchpad_entry_id' => $entry->id,
            'created_by_user_id' => $actor->id,
        ]);

        // Claim the reservation: content_ids rows start with a null
        // entity_type/entity_id at reservation time (the entity doesn't
        // exist yet), and get pointed at the entity once it's created.
        $contentId->forceFill([
            'entity_type' => $idea->getMorphClass(),
            'entity_id' => $idea->id,
        ])->save();

        $from = $entry->status;

        $entry->forceFill([
            'status' => 'triaged',
            'triaged_at' => now(),
            'triaged_by_user_id' => $actor->id,
        ])->save();

        $entry->recordStatusTransition($from, 'triaged');
    }

    private function uniqueSlug(string $title, int $workspaceId, string $kind): string
    {
        $base = Str::slug($title) ?: 'idea';
        $slug = $base;
        $suffix = 1;

        while (
            Idea::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('kind', $kind)
                ->where('slug', $slug)
                ->exists()
        ) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
