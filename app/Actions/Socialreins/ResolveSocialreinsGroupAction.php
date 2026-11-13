<?php

namespace App\Actions\Socialreins;

use App\Models\SocialreinsGroup;
use App\Support\Socialreins\SocialreinsClient;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use App\Support\Socialreins\SocialreinsResults;

/**
 * Settles what happened to a request whose answer was lost, with `GET /idempotency/{key}`:
 * the stored answer is the post group the key created, or the 4xx that refused it. Nothing
 * is guessed and nothing is sent again here.
 */
class ResolveSocialreinsGroupAction
{
    /** Brain has nothing stored under the key: the request never ran (it answered 5xx or 429). */
    public const MISSING = 'missing';

    /** Still unknown: brain is still processing it, or could not be asked. */
    public const PENDING = 'pending';

    public const CREATED = 'created';

    public const REJECTED = 'rejected';

    public function __construct(
        private readonly ReconcileSocialreinsGroupsAction $reconcile,
    ) {}

    public function handle(SocialreinsGroup $row): string
    {
        $client = new SocialreinsClient(SocialreinsConfig::fromWorkspace($row->workspace), $row->language);

        try {
            $record = $client->lookupKey($row->idempotency_key);
        } catch (SocialreinsException $exception) {
            $row->forceFill(['error' => $exception->getMessage()])->save();

            return self::PENDING;
        }

        if ($record === null) {
            return self::MISSING;
        }

        if (($record['status'] ?? null) !== 'done') {
            $row->forceFill(['error' => 'SocialReins is still processing this request. Try again in a few minutes.'])->save();

            return self::PENDING;
        }

        $status = (int) ($record['response_status'] ?? 0);
        $body = is_array($record['response_body'] ?? null) ? $record['response_body'] : [];

        if ($status >= 200 && $status < 300 && is_string($body['group_id'] ?? null) && is_array($body['posts'] ?? null)) {
            $this->markCreated($row, [
                'group_id' => $body['group_id'],
                'posts' => $body['posts'],
                'expected_targets' => (int) ($body['expected_targets'] ?? 0),
                'skipped' => is_array($body['skipped'] ?? null) ? $body['skipped'] : [],
            ]);

            return self::CREATED;
        }

        $row->forceFill([
            'state' => SocialreinsGroup::REJECTED,
            'error' => 'SocialReins refused the request'.(is_string($body['error'] ?? null) ? " ({$body['error']})" : " ({$status})").'.',
        ])->save();

        return self::REJECTED;
    }

    /**
     * Record the group a create answered (or the lookup found), then read it back so the
     * stored picture has brain's own account count.
     *
     * @param  array{group_id: string, posts: array<mixed>, expected_targets: int, skipped: array<mixed>}  $body
     */
    public function markCreated(SocialreinsGroup $row, array $body): void
    {
        $at = time();
        $results = [];
        foreach ($body['posts'] as $post) {
            if (is_array($post) && is_string($post['id'] ?? null)) {
                $results[$post['id']] = SocialreinsResults::fromBrainPost($post, $at);
            }
        }

        $row->forceFill([
            'state' => SocialreinsGroup::CREATED,
            'group_id' => $body['group_id'],
            'expected_targets' => $body['expected_targets'],
            'results' => $results + SocialreinsResults::fromSkipped($body['skipped'], $at),
            'error' => null,
        ])->save();

        $this->reconcile->reconcileGroup($row);
    }
}
