<?php

namespace App\Actions\Socialreins;

use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\TelegramPostRequest;
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
use App\Support\Telegram\TelegramPublishRequests;
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
 * - Every create asks for `on_invalid: skip`: a platform brain left out as invalid has no post
 *   there, so the group retry cannot reach it. The next run plans it again from the subject as
 *   it is now and sends a new group (new sequence, new key) with only those platforms.
 */
class PublishToSocialreinsAction
{
    public function __construct(
        private readonly SettleSocialreinsSubjectAction $settle,
        private readonly ReconcileSocialreinsGroupsAction $reconcile,
        private readonly ResolveSocialreinsGroupAction $resolve,
    ) {}

    /** @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool, telegram_request_id?: int}  $options */
    public function handle(Post|Video $subject, array $options = []): void
    {
        try {
            $subject = $this->claim($subject, $options);
        } catch (PostsyncerException|InvalidArgumentException $exception) {
            // The approval, the Telegram request or its bot connection changed: nothing was sent.
            $this->markFailed($subject, $exception->getMessage());

            return;
        }

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
                $dropped = 0;
                $fresh = $this->createRows($subject, $rows, $options, $dropped);
                $retried = $this->retryFailedGroups($rows);

                if ($fresh === [] && $retried === 0 && $dropped === 0) {
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
        DB::transaction(function () use ($subject, $message): void {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->first();

            if ($locked === null || ! in_array($locked->publish_state, ['queued', 'running'], true)) {
                return;
            }

            $locked->forceFill(['publish_state' => 'failed', 'publish_error' => $message])->save();

            if ($locked instanceof Post) {
                SettleSocialreinsSubjectAction::closeTelegramRequest($locked, 'failed', $message);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws PostsyncerException|InvalidArgumentException
     */
    private function claim(Post|Video $subject, array $options): Post|Video|null
    {
        return DB::transaction(function () use ($subject, $options): Post|Video|null {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->first();

            // A queue redelivery after the first run settled must not run again.
            if ($locked === null || $locked->publish_state !== 'queued') {
                return null;
            }

            // As on the PostSyncer claim: an edit since the enqueue needs a new approval.
            if ($locked instanceof Post && ($locked->approval_state ?? 'approved') !== 'approved') {
                throw new PostsyncerException('This post needs human approval before it can be published.');
            }

            // A split post whose PostSyncer half is live must finish: the user approved the
            // post, so a request that can no longer be locked (bot disconnected or rotated) or
            // that was closed is left as it is, and the settle closes it if it still can.
            $halfLive = is_array($locked->publish_progress['split'] ?? null)
                && ($locked->postsyncer['groups'] ?? []) !== [];

            try {
                $request = $locked instanceof Post
                    ? TelegramPublishRequests::lock($locked, TelegramPublishRequests::idFrom($options))
                    : null;
            } catch (PostsyncerException|InvalidArgumentException $exception) {
                if (! $halfLive) {
                    throw $exception;
                }
                $request = null;
            }

            // As on the PostSyncer claim: a request cancelled or already published in the
            // meantime stops the run before anything is sent.
            if ($request !== null && ! $halfLive
                && in_array($request->state, [TelegramPostRequest::CANCELLED, TelegramPostRequest::PUBLISHED], true)) {
                $locked->forceFill(['publish_state' => 'idle', 'publish_error' => null])->save();

                return null;
            }

            if ($request?->state === TelegramPostRequest::FAILED) {
                $request->forceFill(['state' => TelegramPostRequest::APPROVED, 'error_message' => null])->save();
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
     * @param  int  $dropped  set to the left-out platforms that are no longer planned
     * @return list<SocialreinsGroup>
     */
    private function createRows(Post|Video $subject, array $rows, array $options, int &$dropped = 0): array
    {
        $workspace = $subject->workspace;
        $config = SocialreinsConfig::fromWorkspace($workspace);
        $video = $subject instanceof Video;
        $planner = $video ? app(VideoPublishPlanner::class) : app(PostPublishPlanner::class);

        /** @var list<PublishGroup> $groups */
        $groups = $planner->plan($subject, $config->planningConfig($workspace), $options);

        [$live, $skipped] = $this->liveLanguages($rows);
        $byLanguage = [];
        $planned = [];
        foreach ($groups as $group) {
            $planned[$group->language] = [...($planned[$group->language] ?? []), ...$group->platforms];
            if (! in_array($group->language, $live, true) || isset($skipped[$group->language])) {
                $byLanguage[$group->language][] = $group;
            }
        }

        // A left-out platform the subject no longer plans (it was removed instead of fixed)
        // will never be sent: its record stops counting as a failure.
        foreach ($skipped as $language => $names) {
            foreach (array_diff($names, $planned[$language] ?? []) as $name) {
                foreach ($rows as $row) {
                    if ($row->state === SocialreinsGroup::CREATED && $row->language === $language) {
                        $row->mutateResults(function (array $results) use ($name): ?array {
                            if (! isset($results["skipped:{$name}"])) {
                                return null;
                            }
                            $results["skipped:{$name}"]['status'] = 'deleted';

                            return $results;
                        });
                    }
                }
                $dropped++;
            }
        }

        $builder = new PostGroupPayloadBuilder($config);
        $payloads = [];
        foreach ($byLanguage as $language => $languageGroups) {
            // A live language only gets the platforms brain left out; the rest is never sent again.
            $only = in_array($language, $live, true) ? $skipped[$language] : null;
            $payload = $builder->build($language, $languageGroups, $video, $only);
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
     * Languages with a created group that still has posts (not all taken down), and per
     * language the platforms brain left out of the newest group that named them.
     *
     * @param  list<SocialreinsGroup>  $rows
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    private function liveLanguages(array $rows): array
    {
        $live = [];
        $skipped = [];
        foreach (SocialreinsResults::byPlatform($rows) as $key => $platform) {
            [$language, $name] = explode(':', $key, 2);
            // Taken down, or a left-out platform that was dropped: neither live nor to send.
            if ($platform['state'] === SocialreinsResults::DELETED) {
                continue;
            }
            if ($platform['skipped']) {
                $skipped[$language][] = $name;
            } else {
                $live[] = $language;
            }
        }

        return [array_values(array_unique($live)), $skipped];
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

    /**
     * A platform brain left out has no post in the group: a new group sends it (createRows()).
     *
     * @param  array<string, array<string, mixed>>  $platforms
     */
    private function hasRetryable(array $platforms): bool
    {
        $platforms = array_filter($platforms, fn (array $platform): bool => ! $platform['skipped']);

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
