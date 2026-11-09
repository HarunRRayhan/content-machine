<?php

namespace App\Actions\Socialreins;

use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Support\Postsyncer\PostPublishPlanner;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Postsyncer\PublishGroup;
use App\Support\Postsyncer\VideoPublishPlanner;
use App\Support\Socialreins\PostGroupPayloadBuilder;
use App\Support\Socialreins\SocialreinsClient;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use App\Support\Socialreins\SocialreinsResults;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The publish job's body: plan with CM's own planners, freeze one `POST /post-groups`
 * request per language (one set and token each), and send it with a stable
 * Idempotency-Key.
 *
 * - A request that got no answer stays `pending`. The next run asks `GET /idempotency/{key}`
 *   what the key made; only when brain has nothing stored is the frozen body resent, byte
 *   for byte under the same key.
 * - A request brain refused (4xx) created nothing and was stored with its key, so the next
 *   attempt is a new sequence with a new key.
 * - A group with failed targets is never recreated: `POST /post-groups/{id}/retry` re-queues
 *   only its failed targets that are not ambiguous (each attempt has its own key). Targets
 *   brain reports as `outcome_unknown` are left for the user to check on the platform.
 */
class PublishToSocialreinsAction
{
    public function __construct(
        private readonly SettleSocialreinsSubjectAction $settle,
        private readonly ReconcileSocialreinsGroupsAction $reconcile,
        private readonly ResolveSocialreinsGroupAction $resolve,
    ) {}

    /** @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options */
    public function handle(Post|Video $subject, array $options = []): void
    {
        $subject = $this->claim($subject);

        if ($subject === null) {
            return;
        }

        try {
            $rows = SocialreinsResults::rowsOf($subject);
            $pending = array_values(array_filter($rows, fn (SocialreinsGroup $row): bool => $row->state === SocialreinsGroup::PENDING));

            if ($pending !== []) {
                foreach ($pending as $row) {
                    if ($this->resolve->handle($row) === ResolveSocialreinsGroupAction::MISSING) {
                        $this->send($row);
                    }
                }
            } else {
                $fresh = $this->createRows($subject, $rows, $options);
                $retried = $this->retryFailedGroups($rows);

                if ($fresh === [] && $retried === 0) {
                    throw new SocialreinsException($this->nothingToSend($rows));
                }

                foreach ($fresh as $row) {
                    $this->send($row);
                }
            }
        } catch (SocialreinsException|PostsyncerException|InvalidArgumentException $exception) {
            $this->markFailed($subject, $exception->getMessage());

            return;
        }

        $this->settle->handle($subject, fromJob: true);

        if (($subject->fresh()?->publish_state) === 'running') {
            $this->markFailed($subject, 'SocialReins returned no posts to track.');
        }
    }

    /** Fail a publish that is still running; a state a webhook already settled is kept. */
    public function markFailed(Post|Video $subject, string $message): void
    {
        $subject::query()
            ->whereKey($subject->getKey())
            ->whereIn('publish_state', ['queued', 'running'])
            ->update([
                'publish_state' => 'failed',
                'publish_error' => $message,
                'updated_at' => now(),
            ]);
    }

    private function claim(Post|Video $subject): Post|Video|null
    {
        return DB::transaction(function () use ($subject): Post|Video|null {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->first();

            // A queue redelivery after the first run settled must not run again.
            if ($locked === null || $locked->publish_state !== 'queued') {
                return null;
            }

            $locked->forceFill(['publish_state' => 'running'])->save();

            return $locked;
        });
    }

    /**
     * Plan, and freeze a request for every planned language that has no live group yet. A
     * language that already has posts at SocialReins is never planned again: its failed
     * targets are retried in place.
     *
     * @param  list<SocialreinsGroup>  $rows
     * @param  array<string, mixed>  $options
     * @return list<SocialreinsGroup>
     */
    private function createRows(Post|Video $subject, array $rows, array $options): array
    {
        $workspace = $subject->workspace;
        $config = SocialreinsConfig::fromWorkspace($workspace);
        $video = $subject instanceof Video;
        $planner = $video ? app(VideoPublishPlanner::class) : app(PostPublishPlanner::class);

        /** @var list<PublishGroup> $groups */
        $groups = $planner->plan($subject, $config->planningConfig($workspace), $options);

        $live = $this->liveLanguages($rows);
        $byLanguage = [];
        foreach ($groups as $group) {
            if (! in_array($group->language, $live, true)) {
                $byLanguage[$group->language][] = $group;
            }
        }

        $builder = new PostGroupPayloadBuilder($config);
        $payloads = [];
        foreach ($byLanguage as $language => $languageGroups) {
            $payload = $builder->build($language, $languageGroups, $video);
            if ($payload !== null) {
                $payloads[$language] = PostGroupPayloadBuilder::encode($payload);
            }
        }

        if ($payloads === []) {
            return [];
        }

        $kind = $video ? 'video' : 'post';

        return DB::transaction(function () use ($subject, $payloads, $kind): array {
            $subject::query()->whereKey($subject->getKey())->lockForUpdate()->first();
            $created = [];

            foreach ($payloads as $language => $json) {
                $sequence = 1 + (int) SocialreinsGroup::query()
                    ->where('subject_type', $kind)
                    ->where('subject_id', $subject->getKey())
                    ->where('language', $language)
                    ->max('sequence');

                $created[] = SocialreinsGroup::query()->create([
                    'workspace_id' => $subject->workspace_id,
                    'subject_type' => $kind,
                    'subject_id' => $subject->getKey(),
                    'language' => $language,
                    'sequence' => $sequence,
                    'idempotency_key' => "cm:ws{$subject->workspace_id}:{$kind}:{$subject->getKey()}:{$language}:{$sequence}",
                    'state' => SocialreinsGroup::PENDING,
                    'request' => $json,
                ]);
            }

            return $created;
        });
    }

    /**
     * Languages with a created group that still has posts (not all taken down).
     *
     * @param  list<SocialreinsGroup>  $rows
     * @return list<string>
     */
    private function liveLanguages(array $rows): array
    {
        $live = [];
        foreach (SocialreinsResults::byPlatform($rows) as $key => $platform) {
            if ($platform['state'] !== SocialreinsResults::DELETED) {
                $live[] = explode(':', $key, 2)[0];
            }
        }

        return array_values(array_unique($live));
    }

    /**
     * Retry every created group that has a failed target. Brain decides what is safe: it
     * re-queues only failed targets that are not ambiguous and lists the rest as skipped.
     *
     * @param  list<SocialreinsGroup>  $rows
     * @return int groups a retry was sent for
     */
    private function retryFailedGroups(array $rows): int
    {
        $sent = 0;

        foreach ($rows as $row) {
            if ($row->state !== SocialreinsGroup::CREATED
                || ! $this->hasRetryable(SocialreinsResults::byPlatform([$row], requireComplete: false))) {
                continue;
            }

            // A webhook names one target; the retry should act on the whole group as it is now.
            if (! $this->reconcile->reconcileGroup($row)) {
                throw new SocialreinsException(
                    "Could not ask SocialReins about the failed posts of {$row->language}. Nothing was retried; try again shortly."
                );
            }

            if (! $this->hasRetryable(SocialreinsResults::byPlatform([$row->refresh()], requireComplete: false))) {
                continue;
            }

            $this->retryGroup($row);
            $sent++;
        }

        return $sent;
    }

    /** @param  array<string, array<string, mixed>>  $platforms */
    private function hasRetryable(array $platforms): bool
    {
        return array_intersect(array_column($platforms, 'state'), [SocialreinsResults::FAILED, SocialreinsResults::PARTIAL]) !== [];
    }

    private function retryGroup(SocialreinsGroup $row): void
    {
        $client = new SocialreinsClient(SocialreinsConfig::fromWorkspace($row->workspace), $row->language);
        // One key per attempt: the retry has no body, so a reused key would replay an old answer.
        $key = "cm:ws{$row->workspace_id}:retry:{$row->group_id}:{$row->retry_attempt}";

        try {
            $body = $client->retryGroup((string) $row->group_id, $key);
        } catch (SocialreinsException $exception) {
            // Brain stored this answer under the key, so the next attempt needs a new one.
            if ($exception->isDefinitive()) {
                $row->mutate(function (SocialreinsGroup $locked): void {
                    $locked->retry_attempt++;
                });
            }

            match ($exception->errorCode) {
                'outcome_unknown' => $this->flagUnknown($row, (array) ($exception->body['targets'] ?? [])),
                // Still going out, or nothing failed any more: the settle that follows says which.
                'publish_in_progress', 'nothing_to_retry' => null,
                default => throw $exception,
            };

            return;
        }

        $at = time();
        $unknown = array_values(array_filter(array_map(
            fn (mixed $skipped): ?string => is_array($skipped) && is_string($skipped['target_id'] ?? null) ? $skipped['target_id'] : null,
            $body['skipped_targets'],
        )));

        $row->mutate(function (SocialreinsGroup $locked) use ($body, $at, $unknown): void {
            $results = $locked->results ?? [];
            foreach ($body['posts'] as $post) {
                if (is_array($post) && is_string($post['id'] ?? null)) {
                    $results[$post['id']] = SocialreinsResults::afterRetry(
                        $results[$post['id']] ?? ['targets' => []],
                        SocialreinsResults::fromBrainPost($post, $at),
                        $at,
                        $unknown,
                    );
                }
            }
            $locked->results = $results;
            $locked->expected_targets = $body['expected_targets'];
            $locked->retry_attempt++;
        });
    }

    /** @param  array<mixed>  $targets  brain's `targets` of a 409 outcome_unknown */
    private function flagUnknown(SocialreinsGroup $row, array $targets): void
    {
        $row->mutateResults(function (array $results) use ($targets): array {
            foreach ($targets as $target) {
                if (is_array($target) && isset($results[$target['post_id'] ?? '']['targets'][$target['target_id'] ?? ''])) {
                    $results[$target['post_id']]['targets'][$target['target_id']]['unknown'] = true;
                }
            }

            return $results;
        });
    }

    /** @param  list<SocialreinsGroup>  $rows */
    private function nothingToSend(array $rows): string
    {
        $platforms = SocialreinsResults::byPlatform($rows);
        $unknown = SocialreinsResults::withState($platforms, SocialreinsResults::UNKNOWN);

        return $unknown->isNotEmpty()
            ? SettleSocialreinsSubjectAction::unknownMessage($unknown)
            : 'Nothing to send: no platform is enabled for this post, or it is already published or still publishing.';
    }

    private function send(SocialreinsGroup $row): void
    {
        $client = new SocialreinsClient(SocialreinsConfig::fromWorkspace($row->workspace), $row->language);

        try {
            $body = $client->createGroup($row->idempotency_key, $row->request);
        } catch (SocialreinsException $exception) {
            $row->forceFill([
                // A stored 4xx created nothing and would replay forever: close the row so
                // the next attempt gets a new key. Anything else may or may not have
                // happened, so the row stays pending and is looked up, then resent unchanged.
                'state' => $exception->isDefinitive() ? SocialreinsGroup::REJECTED : SocialreinsGroup::PENDING,
                'error' => $exception->getMessage(),
            ])->save();

            // The key was used by a different body: ask what it made instead of guessing.
            if ($exception->errorCode === 'idempotency_key_reused') {
                $this->resolve->handle($row);
            }

            return;
        }

        $this->resolve->markCreated($row, $body);
    }
}
