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
 * A request that got no answer stays `pending` and is resent byte for byte under the same
 * key, so a retry days later returns the first result instead of posting twice. A request
 * brain refused (4xx) created nothing and was stored with its key, so the next attempt is a
 * new sequence with a new key. After a partial failure a retry plans again but sends only
 * the failed platforms: never one that has a published target, a remote id or thread parts,
 * because brain posts to every account of a platform and would repost what is already live.
 */
class PublishToSocialreinsAction
{
    public function __construct(
        private readonly SettleSocialreinsSubjectAction $settle,
        private readonly ReconcileSocialreinsGroupsAction $reconcile,
    ) {}

    /** @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options */
    public function handle(Post|Video $subject, array $options = []): void
    {
        $subject = $this->claim($subject);

        if ($subject === null) {
            return;
        }

        try {
            foreach ($this->rowsToSend($subject, $options) as $row) {
                $this->send($row);
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
     * @param  array<string, mixed>  $options
     * @return list<SocialreinsGroup>
     */
    private function rowsToSend(Post|Video $subject, array $options): array
    {
        $rows = SocialreinsResults::rowsOf($subject);
        $pending = array_values(array_filter($rows, fn (SocialreinsGroup $row): bool => $row->state === SocialreinsGroup::PENDING));

        return $pending !== [] ? $pending : $this->createRows($subject, $rows, $options);
    }

    /**
     * @param  list<SocialreinsGroup>  $rows
     * @param  array<string, mixed>  $options
     * @return list<SocialreinsGroup>
     */
    private function createRows(Post|Video $subject, array $rows, array $options): array
    {
        $rows = $this->refreshedRows($subject, $rows);
        $workspace = $subject->workspace;
        $config = SocialreinsConfig::fromWorkspace($workspace);
        $video = $subject instanceof Video;
        $planner = $video ? app(VideoPublishPlanner::class) : app(PostPublishPlanner::class);

        /** @var list<PublishGroup> $groups */
        $groups = $planner->plan($subject, $config->planningConfig($workspace), $options);

        $byLanguage = [];
        foreach ($groups as $group) {
            $byLanguage[$group->language][] = $group;
        }

        $only = $this->platformsStillToSend($rows);
        $builder = new PostGroupPayloadBuilder($config);
        $payloads = [];
        foreach ($byLanguage as $language => $languageGroups) {
            $payload = $builder->build($language, $languageGroups, $video, $only[$language] ?? null);
            if ($payload !== null) {
                $payloads[$language] = PostGroupPayloadBuilder::encode($payload);
            }
        }

        if ($payloads === []) {
            $partial = SocialreinsResults::withState(SocialreinsResults::byPlatform($rows), SocialreinsResults::PARTIAL);

            throw new SocialreinsException($partial->isNotEmpty()
                ? SettleSocialreinsSubjectAction::partialMessage($partial)
                : 'Nothing to send: no platform is enabled for this post, or it is already published or still publishing.');
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
     * Before deciding what to resend, ask brain for the full picture of every group with a
     * failure: a webhook does not say whether an account or a thread part already went out.
     * When brain cannot be asked, nothing is resent.
     *
     * @param  list<SocialreinsGroup>  $rows
     * @return list<SocialreinsGroup>
     */
    private function refreshedRows(Post|Video $subject, array $rows): array
    {
        $checked = false;
        foreach ($rows as $row) {
            $states = array_column(SocialreinsResults::byPlatform([$row]), 'state');
            if ($row->state === SocialreinsGroup::CREATED
                && array_intersect($states, [SocialreinsResults::FAILED, SocialreinsResults::PARTIAL]) !== []) {
                if (! $this->reconcile->reconcileGroup($row)) {
                    throw new SocialreinsException(
                        "Could not ask SocialReins about the failed posts of {$row->language}, so CM cannot tell "
                        .'whether part of them is already live. Nothing was resent; try again shortly.'
                    );
                }
                $checked = true;
            }
        }

        return $checked ? SocialreinsResults::rowsOf($subject) : $rows;
    }

    /**
     * A language that already has posts at SocialReins resends only its failed platforms;
     * a language with none (or only taken-down ones) goes out in full.
     *
     * @param  list<SocialreinsGroup>  $rows
     * @return array<string, list<string>>
     */
    private function platformsStillToSend(array $rows): array
    {
        $byLanguage = [];
        foreach (SocialreinsResults::byPlatform($rows) as $key => $platform) {
            [$language, $name] = explode(':', $key, 2);
            $byLanguage[$language][$name] = $platform['state'];
        }

        $only = [];
        foreach ($byLanguage as $language => $states) {
            if (array_unique(array_values($states)) === [SocialreinsResults::DELETED]) {
                continue;
            }
            $only[$language] = array_keys(array_filter($states, fn (string $state): bool => $state === SocialreinsResults::FAILED));
        }

        return $only;
    }

    private function send(SocialreinsGroup $row): void
    {
        $client = new SocialreinsClient(SocialreinsConfig::fromWorkspace($row->workspace), $row->language);
        $at = time();

        try {
            $body = $client->createGroup($row->idempotency_key, $row->request);
        } catch (SocialreinsException $exception) {
            $row->forceFill([
                // A stored 4xx created nothing and would replay forever: close the row so
                // the next attempt gets a new key. Anything else may or may not have
                // happened, so the row stays pending and is resent unchanged.
                'state' => $exception->isDefinitive() ? SocialreinsGroup::REJECTED : SocialreinsGroup::PENDING,
                'error' => $exception->getMessage(),
            ])->save();

            return;
        }

        $results = [];
        foreach ($body['posts'] as $post) {
            if (is_array($post) && is_string($post['id'] ?? null)) {
                $results[$post['id']] = SocialreinsResults::fromBrainPost($post, $at);
            }
        }

        $row->forceFill([
            'state' => SocialreinsGroup::CREATED,
            'group_id' => $body['group_id'],
            'results' => $results,
            'error' => null,
        ])->save();
    }
}
