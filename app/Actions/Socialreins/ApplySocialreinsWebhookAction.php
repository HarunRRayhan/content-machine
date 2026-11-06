<?php

namespace App\Actions\Socialreins;

use App\Models\SocialreinsGroup;
use App\Support\Socialreins\SocialreinsResults;

/**
 * Applies one verified `post.target.published|failed|deleted` event to the group it
 * belongs to, then asks brain for the whole group before settling the Post or Video. An
 * event names one target only; the group's real account count and the post's own status
 * come from `GET /posts?group_id=`, so the first of two accounts publishing never marks the
 * post posted. If brain cannot be asked, the post stays unsettled and the reconcile run
 * finishes it. Applying the same event twice changes nothing.
 */
class ApplySocialreinsWebhookAction
{
    public function __construct(
        private readonly SettleSocialreinsSubjectAction $settle,
        private readonly ReconcileSocialreinsGroupsAction $reconcile,
    ) {}

    /**
     * @param  array<string, mixed>  $event
     * @return bool false when the event names no group of this workspace (brain retries it)
     */
    public function handle(int $workspaceId, array $event): bool
    {
        $type = $event['type'] ?? null;
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        if (! is_string($type) || ! str_starts_with($type, 'post.target.')) {
            return true; // `ping` and future event types need no action
        }

        $row = is_string($data['group_id'] ?? null)
            ? SocialreinsGroup::query()
                ->where('workspace_id', $workspaceId)
                ->where('group_id', $data['group_id'])
                ->first()
            : null;

        if ($row === null) {
            return false;
        }

        $at = is_string($event['created_at'] ?? null) ? (strtotime($event['created_at']) ?: time()) : time();
        $known = false;
        $row->mutateResults(function (array $results) use ($data, $at, &$known): ?array {
            $merged = SocialreinsResults::withTargetEvent($results, $data, $at);
            $known = $merged !== null;

            return $merged;
        });

        if (! $known) {
            return false;
        }

        $this->reconcile->reconcileGroup($row);

        $subject = $row->subject();
        if ($subject !== null) {
            $this->settle->handle($subject);
        }

        return true;
    }
}
