<?php

namespace App\Actions\Socialreins;

use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsResults;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Queue a SocialReins publish for a Post or Video in a workspace whose driver is
 * SocialReins. EnqueuePostPublishAction and EnqueueVideoPublishAction hand over to this.
 */
class EnqueueSocialreinsPublishAction
{
    /**
     * @template T of Post|Video
     *
     * @param  T  $subject
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     * @return T
     */
    public function handle(Post|Video $subject, Workspace $workspace, array $options = []): Post|Video
    {
        abort_if($subject->workspace_id !== $workspace->id, 404);

        if (! SocialreinsConfig::fromWorkspace($workspace)->isReadyForPublish()) {
            throw $this->refuse(__('SocialReins is not configured for publishing: set the base URL, a set id and a token in Settings.'));
        }

        $options = array_intersect_key(
            array_filter($options, fn (mixed $value): bool => $value !== null),
            array_flip(['when', 'platforms', 'confirm_ask']),
        );

        /** @var T $queued */
        $queued = DB::transaction(function () use ($subject, $options): Post|Video {
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

            if ($locked instanceof Post) {
                if (($locked->approval_state ?? 'approved') !== 'approved') {
                    throw $this->refuse(__('This post needs human approval before it can be published.'));
                }
                if ($locked->status === 'blocked') {
                    throw $this->refuse(__('This post is blocked. Unblock it before publishing.'));
                }
            }

            // A queued or running row only blocks while its worker could still be alive.
            if (in_array($locked->publish_state, ['queued', 'running'], true)
                && $locked->updated_at?->isAfter(now()->subSeconds(PublishSocialreinsJob::LEASE_SECONDS))) {
                throw $this->refuse(__('A publish is already in progress.'));
            }

            if ($this->isSettledAndClean($locked)) {
                throw $this->refuse(__('This is already published through SocialReins. Take it down first to publish again.'));
            }

            $locked->forceFill([
                'publish_state' => 'queued',
                'publish_error' => null,
                // `driver` keeps the PostSyncer recovery sweep away from the row. `state` is
                // read only once publish_state is failed, and makes canRetryPublish() true:
                // a SocialReins retry is always safe (frozen request, same Idempotency-Key).
                'publish_progress' => ['driver' => SocialreinsConfig::DRIVER, 'state' => 'failed', 'options' => $options],
            ])->save();

            // The database queue shares this connection: the job commits with the row.
            dispatch(new PublishSocialreinsJob($locked, $options))->beforeCommit();

            return $locked;
        });

        return $queued->fresh() ?? $queued;
    }

    /** Succeeded with nothing failed, pending or taken down: a retry would only duplicate. */
    private function isSettledAndClean(Post|Video $subject): bool
    {
        if ($subject->publish_state !== 'succeeded') {
            return false;
        }

        $states = array_column(SocialreinsResults::byPlatform(SocialreinsResults::rowsOf($subject)), 'state');

        return $states !== [] && array_diff($states, [SocialreinsResults::OK, SocialreinsResults::WAITING]) === [];
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['publish' => $message]);
    }
}
