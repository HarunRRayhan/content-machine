<?php

namespace App\Actions\Videos;

use App\Actions\Ids\ReserveContentIdAction;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a video row. Pass human_id+number for an idempotent import from
 * personal-content (BV-53); omit them to reserve the next V-N id.
 */
class CreateVideoAction
{
    public function __construct(
        private readonly ReserveContentIdAction $reserveContentIdAction,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Workspace $workspace, array $attributes): Video
    {
        return DB::transaction(function () use ($workspace, $attributes) {
            $humanId = isset($attributes['human_id']) ? (string) $attributes['human_id'] : null;

            if ($humanId !== null) {
                $existing = Video::query()
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
                $contentId = $this->reserveContentIdAction->handle($workspace, 'video');
                $humanId = $contentId->human_id;
                $number = $contentId->number;
            }

            $status = (string) ($attributes['status'] ?? 'draft');
            if (! in_array($status, Video::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => "Invalid video status [{$status}]."]);
            }

            $video = Video::create([
                'workspace_id' => $workspace->id,
                'idea_id' => $attributes['idea_id'] ?? null,
                'number' => $number,
                'human_id' => $humanId,
                'title' => (string) $attributes['title'],
                'language' => $attributes['language'] ?? null,
                'slug' => $attributes['slug'] ?? null,
                'body' => $attributes['body'] ?? null,
                'script_markdown' => $attributes['script_markdown'] ?? null,
                'captions' => $attributes['captions'] ?? null,
                'deck_manifest' => $attributes['deck_manifest'] ?? null,
                'status' => $status,
                'created_by_user_id' => Auth::id(),
            ]);

            if (isset($contentId)) {
                $contentId->forceFill([
                    'entity_type' => $video->getMorphClass(),
                    'entity_id' => $video->id,
                ])->save();
            }

            return $video;
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
