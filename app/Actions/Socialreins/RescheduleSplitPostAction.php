<?php

namespace App\Actions\Socialreins;

use App\Actions\Postsyncer\ReschedulePostAction;
use App\Actions\Postsyncer\VideoRescheduleAction;
use App\Data\Postsyncer\ReschedulePostData;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Socialreins\SocialreinsClient;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use App\Support\Socialreins\SocialreinsResults;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Moves both halves of a split post or video to a new time: the PostSyncer groups through
 * ReschedulePostAction or VideoRescheduleAction (their own checkpoint), then each SocialReins
 * post with `PUT /posts/{id}`.
 *
 * Every SocialReins post is read first and must still be scheduled, so a post that already went
 * out stops the reschedule before anything moves. A post already at the target time is skipped,
 * so retrying with the same time finishes a partial run. Each moved post records its new
 * `scheduled_at` in its stored result, and an error names the half that failed.
 *
 * A post or video that went whole to SocialReins has no PostSyncer half: only its SocialReins
 * posts move, and PostSyncer does not have to be configured.
 *
 * One reschedule runs at a time per post or video (see claim()).
 */
class RescheduleSplitPostAction
{
    /** Key of the reschedule lease in `publish_progress`. */
    private const LEASE = 'socialreins_reschedule';

    /** As VideoRescheduleAction: longer than any reschedule takes. */
    private const LEASE_MINUTES = 15;

    public function __construct(
        private readonly ReschedulePostAction $postsyncer,
        private readonly VideoRescheduleAction $videos,
    ) {}

    public function handle(Post|Video $post, Workspace $workspace, ReschedulePostData $data): Post|Video
    {
        abort_if($post->workspace_id !== $workspace->id, 404);

        $post->refresh();
        $progress = is_array($post->publish_progress) ? $post->publish_progress : [];
        $split = is_array($progress['split'] ?? null);

        // Published through PostSyncer alone: nothing here to move.
        if (! $split && ($progress['driver'] ?? null) !== SocialreinsConfig::DRIVER) {
            return $post instanceof Video
                ? $this->videos->handle($post, $workspace, $data)
                : $this->postsyncer->handle($post, $workspace, $data);
        }

        $target = $data->when->setSecond(0)->utc();

        $this->claim($post, $split, $target);

        try {
            $post = $this->move($post, $workspace, $data, $split, $target);
        } finally {
            $this->release($post);
        }

        return $post->refresh();
    }

    /**
     * Admits one reschedule at a time, as VideoRescheduleAction's marker does for the PostSyncer
     * groups: a lease in `publish_progress`, written under the row lock the publish path takes,
     * so two calls cannot interleave their PUTs. It expires, so a crashed call does not lock the
     * post for good. The rules of the SocialReins-only path are checked under the same lock.
     */
    private function claim(Post|Video $post, bool $split, CarbonImmutable $target): void
    {
        DB::transaction(function () use ($post, $split, $target): void {
            $locked = $post::query()->whereKey($post->getKey())->lockForUpdate()->firstOrFail();
            $noun = $locked instanceof Video ? 'video' : 'post';

            if (! $split) {
                // The rules the PostSyncer actions apply, which the split path gets from them.
                if ($locked->status !== 'scheduled') {
                    throw new SocialreinsException("Only scheduled {$noun}s can be rescheduled.");
                }
                if (in_array($locked->publish_state, ['queued', 'running'], true)) {
                    throw new SocialreinsException('A publish is already in progress.');
                }
                if ($locked instanceof Video && ($locked->publish_state !== 'succeeded' || $locked->hasUncertainPublish())) {
                    throw new SocialreinsException('The video has a publish in progress or an uncertain publish. Reconcile it first.');
                }
                if ($target->lessThanOrEqualTo(CarbonImmutable::now())) {
                    throw new SocialreinsException('The new schedule must be in the future.');
                }
            }

            $progress = is_array($locked->publish_progress) ? $locked->publish_progress : [];
            $startedAt = $progress[self::LEASE]['started_at'] ?? null;
            if (is_string($startedAt) && CarbonImmutable::parse($startedAt)->addMinutes(self::LEASE_MINUTES)->isFuture()) {
                throw new SocialreinsException("Another reschedule is already running for this {$noun}.");
            }

            $progress[self::LEASE] = ['started_at' => CarbonImmutable::now()->toIso8601String()];
            $locked->forceFill(['publish_progress' => $progress])->save();
        });
    }

    private function release(Post|Video $post): void
    {
        DB::transaction(function () use ($post): void {
            $locked = $post::query()->whereKey($post->getKey())->lockForUpdate()->first();
            $progress = $locked?->publish_progress;

            if ($locked !== null && is_array($progress) && isset($progress[self::LEASE])) {
                unset($progress[self::LEASE]);
                $locked->forceFill(['publish_progress' => $progress])->save();
            }
        });
    }

    private function move(Post|Video $post, Workspace $workspace, ReschedulePostData $data, bool $split, CarbonImmutable $target): Post|Video
    {
        $config = SocialreinsConfig::fromWorkspace($workspace);
        $todo = $this->socialreinsPosts($post, $config, $target);

        if ($split) {
            try {
                $post = $post instanceof Video
                    ? $this->videos->handle($post, $workspace, $data)
                    : $this->postsyncer->handle($post, $workspace, $data);
            } catch (PostsyncerException $exception) {
                throw new PostsyncerException(
                    'PostSyncer half not rescheduled (the SocialReins half was not changed): '.$exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }

        $moved = [];
        foreach ($todo as [$row, $postId, $live, $name]) {
            if ($live !== null) {
                try {
                    (new SocialreinsClient($config, $row->language))->updatePost($postId, [
                        ...array_intersect_key($live, array_flip(['social_set_id', 'platform', 'body', 'media', 'thread', 'first_comment', 'cover', 'tiktok', 'youtube'])),
                        'scheduled_at' => $target->format('Y-m-d\TH:i:s\Z'),
                    ]);
                } catch (SocialreinsException $exception) {
                    throw new SocialreinsException(
                        ($split ? 'The PostSyncer half moved, but the SocialReins half did not finish. ' : 'The SocialReins reschedule did not finish. ')
                            .($moved === [] ? '' : 'Moved: '.implode(', ', $moved).'. ')
                            ."{$name} failed: {$exception->getMessage()} Retry with the same time to finish.",
                        $exception->status,
                        $exception->errorCode,
                    );
                }
            }

            $row->mutateResults(function (array $results) use ($postId, $target): ?array {
                if (! isset($results[$postId])) {
                    return null;
                }
                $results[$postId]['scheduled_at'] = $target->format('Y-m-d\TH:i:s\Z');

                return $results;
            });
            $moved[] = $name;
        }

        return $post;
    }

    /**
     * Every SocialReins post of the split, read live. Null `live` means it is already at the target.
     *
     * @return list<array{0: SocialreinsGroup, 1: string, 2: array<string, mixed>|null, 3: string}>
     */
    private function socialreinsPosts(Post|Video $post, SocialreinsConfig $config, CarbonImmutable $target): array
    {
        $rows = SocialreinsResults::rowsOf($post);
        $latest = [];
        foreach ($rows as $row) {
            $latest[$row->language] = $row;
        }

        $todo = [];
        foreach ($latest as $language => $row) {
            if ($row->state !== SocialreinsGroup::CREATED) {
                throw new SocialreinsException("SocialReins half ({$language}) is not fully handed over. Settle the publish before rescheduling.");
            }

            foreach (array_keys($row->results ?? []) as $postId) {
                $postId = (string) $postId;
                $name = "{$language} ".$row->results[$postId]['platform'];

                try {
                    $live = (new SocialreinsClient($config, $language))->post($postId);
                } catch (SocialreinsException $exception) {
                    throw new SocialreinsException("Nothing was rescheduled: could not read {$name} in SocialReins. {$exception->getMessage()}");
                }

                $at = is_string($live['scheduled_at'] ?? null) ? CarbonImmutable::parse($live['scheduled_at'])->utc()->setSecond(0) : null;
                if ($at !== null && $at->equalTo($target) && ($live['status'] ?? null) === 'scheduled') {
                    $todo[] = [$row, $postId, null, $name];

                    continue;
                }

                if (($live['status'] ?? null) !== 'scheduled') {
                    throw new SocialreinsException("Nothing was rescheduled: {$name} is ".($live['status'] ?? 'unknown')
                        .' in SocialReins, not scheduled. Only a post whose every platform is still scheduled can move.');
                }

                $todo[] = [$row, $postId, $live, $name];
            }
        }

        if ($todo === []) {
            throw new SocialreinsException('This post has no SocialReins half to reschedule.');
        }

        return $todo;
    }
}
