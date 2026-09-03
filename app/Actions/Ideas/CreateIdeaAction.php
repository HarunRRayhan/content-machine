<?php

namespace App\Actions\Ideas;

use App\Actions\Ids\ReserveContentIdAction;
use App\Models\Idea;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates an idea. Pass human_id (PI-20 / VI-27) for an idempotent import
 * from personal-content; omit it to reserve the next PI/VI id.
 */
class CreateIdeaAction
{
    private const STATUSES = ['open', 'promoted', 'dropped'];

    private const KINDS = ['post', 'video', 'feature'];

    public function __construct(
        private readonly ReserveContentIdAction $reserveContentIdAction,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Workspace $workspace, array $attributes): Idea
    {
        return DB::transaction(function () use ($workspace, $attributes) {
            $kind = (string) ($attributes['kind'] ?? '');
            if (! in_array($kind, self::KINDS, true)) {
                throw ValidationException::withMessages(['kind' => "Invalid idea kind [{$kind}]."]);
            }

            $humanId = isset($attributes['human_id']) ? (string) $attributes['human_id'] : null;

            if ($humanId !== null) {
                $existing = Idea::query()
                    ->where('workspace_id', $workspace->id)
                    ->where('human_id', $humanId)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                $number = isset($attributes['number'])
                    ? (int) $attributes['number']
                    : $this->numberFromHumanId($humanId);

                $reserveKind = match ($kind) {
                    'post' => 'post_idea',
                    'video' => 'video_idea',
                    default => null,
                };

                if ($reserveKind !== null) {
                    $this->reserveContentIdAction->advancePast($workspace, $reserveKind, $number);
                }
            } else {
                $reserveKind = match ($kind) {
                    'post' => 'post_idea',
                    'video' => 'video_idea',
                    default => throw ValidationException::withMessages([
                        'kind' => 'feature ideas must be imported with an explicit human_id.',
                    ]),
                };
                $contentId = $this->reserveContentIdAction->handle($workspace, $reserveKind);
                $humanId = $contentId->human_id;
                $number = $contentId->number;
            }

            $status = (string) ($attributes['status'] ?? 'open');
            if (! in_array($status, self::STATUSES, true)) {
                throw ValidationException::withMessages(['status' => "Invalid idea status [{$status}]."]);
            }

            $title = (string) $attributes['title'];
            $slug = isset($attributes['slug']) && is_string($attributes['slug']) && $attributes['slug'] !== ''
                ? (string) $attributes['slug']
                : $this->uniqueSlug($title, $workspace->id, $kind);

            $idea = Idea::create([
                'workspace_id' => $workspace->id,
                'kind' => $kind,
                'number' => $number,
                'human_id' => $humanId,
                'title' => $title,
                'slug' => $slug,
                'score' => $attributes['score'] ?? null,
                'trend' => $attributes['trend'] ?? null,
                'rationale' => $attributes['rationale'] ?? null,
                'body' => $attributes['body'] ?? null,
                'editorial_type' => $attributes['editorial_type'] ?? null,
                'status' => $status,
                'details' => $attributes['details'] ?? [],
                'created_by_user_id' => Auth::id(),
            ]);

            if (isset($contentId)) {
                $contentId->forceFill([
                    'entity_type' => $idea->getMorphClass(),
                    'entity_id' => $idea->id,
                ])->save();
            }

            return $idea;
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

    private function uniqueSlug(string $title, int $workspaceId, string $kind): string
    {
        $base = Str::slug($title) ?: 'idea';
        $slug = $base;
        $suffix = 1;

        while (
            Idea::query()
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
