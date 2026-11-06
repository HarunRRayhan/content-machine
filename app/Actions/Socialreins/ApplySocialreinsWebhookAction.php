<?php

namespace App\Actions\Socialreins;

use App\Models\SocialreinsGroup;
use App\Support\Socialreins\SocialreinsResults;

/**
 * Applies one verified `post.target.published|failed|deleted` event to the group it
 * belongs to, then settles the Post or Video. Applying the same event twice changes nothing.
 */
class ApplySocialreinsWebhookAction
{
    public function __construct(
        private readonly SettleSocialreinsSubjectAction $settle,
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

        $results = $row !== null ? SocialreinsResults::withTargetEvent($row->results ?? [], $data) : null;

        if ($row === null || $results === null) {
            return false;
        }

        $row->forceFill(['results' => $results])->save();

        $subject = $row->subject();
        if ($subject !== null) {
            $this->settle->handle($subject);
        }

        return true;
    }
}
