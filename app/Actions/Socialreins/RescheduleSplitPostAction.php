<?php

namespace App\Actions\Socialreins;

use App\Actions\Postsyncer\ReschedulePostAction;
use App\Data\Postsyncer\ReschedulePostData;
use App\Models\Post;
use App\Models\SocialreinsGroup;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerException;
use App\Support\Socialreins\SocialreinsClient;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use App\Support\Socialreins\SocialreinsResults;
use Carbon\CarbonImmutable;

/**
 * Moves both halves of a split post to a new time: the PostSyncer groups through
 * ReschedulePostAction (its own checkpoint), then each SocialReins post with `PUT /posts/{id}`.
 *
 * Every SocialReins post is read first and must still be scheduled, so a post that already went
 * out stops the reschedule before anything moves. A post already at the target time is skipped,
 * so retrying with the same time finishes a partial run. Each moved post records its new
 * `scheduled_at` in its stored result, and an error names the half that failed.
 */
class RescheduleSplitPostAction
{
    public function __construct(
        private readonly ReschedulePostAction $postsyncer,
    ) {}

    public function handle(Post $post, Workspace $workspace, ReschedulePostData $data): Post
    {
        abort_if($post->workspace_id !== $workspace->id, 404);

        $config = SocialreinsConfig::fromWorkspace($workspace);
        $target = $data->when->setSecond(0)->utc();
        $todo = $this->socialreinsPosts($post, $config, $target);

        try {
            $post = $this->postsyncer->handle($post, $workspace, $data);
        } catch (PostsyncerException $exception) {
            throw new PostsyncerException(
                'PostSyncer half not rescheduled (the SocialReins half was not changed): '.$exception->getMessage(),
                0,
                $exception,
            );
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
                        'The PostSyncer half moved, but the SocialReins half did not finish. '
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
    private function socialreinsPosts(Post $post, SocialreinsConfig $config, CarbonImmutable $target): array
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
