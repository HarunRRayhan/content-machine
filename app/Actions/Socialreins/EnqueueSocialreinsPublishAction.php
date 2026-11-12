<?php

namespace App\Actions\Socialreins;

use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\TelegramPostRequest;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsResults;
use App\Support\Telegram\TelegramPublishRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Queue a SocialReins publish for a Post or Video that routes to SocialReins.
 * EnqueuePostPublishAction and EnqueueVideoPublishAction hand over to this.
 */
class EnqueueSocialreinsPublishAction
{
    /**
     * @template T of Post|Video
     *
     * @param  T  $subject
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool, telegram_request_id?: int|string}  $options
     * @return T
     */
    public function handle(Post|Video $subject, Workspace $workspace, array $options = []): Post|Video
    {
        abort_if($subject->workspace_id !== $workspace->id, 404);

        if (! SocialreinsConfig::fromWorkspace($workspace)->isReadyForPublish()) {
            throw $this->refuse(__('SocialReins is not configured for publishing: set the base URL, a set id and a token in Settings.'));
        }

        $requested = $options;
        $options = array_intersect_key(
            array_filter($options, fn (mixed $value): bool => $value !== null),
            array_flip(['when', 'platforms', 'confirm_ask', 'telegram_request_id']),
        );

        // Only posts have Telegram requests.
        if ($subject instanceof Video) {
            unset($options['telegram_request_id']);
        }

        try {
            $requestId = TelegramPublishRequests::idFrom($options);
        } catch (InvalidArgumentException) {
            throw $this->refuse(__('The Telegram publish request is invalid.'));
        }

        /** @var T $queued */
        $queued = DB::transaction(function () use ($subject, $workspace, $requested, $options, $requestId): Post|Video {
            // Same lock order as the PostSyncer path and the settings save: workspace, then subject.
            $lockedWorkspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

            // The route was read before this lock: never start on the other driver's subject.
            if (! SocialreinsConfig::routesToSocialreins($locked, $lockedWorkspace, $requested)) {
                throw $this->refuse(__('The publish route changed. Try again.'));
            }

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

            // The SocialReins half of a split post keeps its own options: other platforms
            // went through PostSyncer and must never be sent here as well.
            $split = $locked->publish_progress['split'] ?? null;
            if (is_array($split) && is_array($split['socialreins'] ?? null)) {
                $options = $split['socialreins'];
            }

            // Same admission as the PostSyncer enqueue: the request must be approved (or failed,
            // on a retry), and the run that closes it keeps its id in the stored options.
            unset($options['telegram_request_id']);
            $telegramRequest = null;
            if ($locked instanceof Post) {
                // A run that never settled (its worker died) left its request open: keep it.
                $stored = $locked->publish_progress['options']['telegram_request_id'] ?? null;
                if ($requestId === null && is_int($stored) && $locked->telegramPostRequests()->whereKey($stored)
                    ->whereIn('state', [TelegramPostRequest::APPROVED, TelegramPostRequest::FAILED])->exists()) {
                    $requestId = $stored;
                }

                $telegramRequest = TelegramPublishRequests::admit($locked, $requestId, $locked->publish_state === 'failed');
            }
            if ($telegramRequest !== null) {
                $options['telegram_request_id'] = $telegramRequest->id;
            }

            $locked->forceFill([
                'publish_state' => 'queued',
                'publish_error' => null,
                // `driver` keeps the PostSyncer recovery sweep away from the row. `state` is
                // read only once publish_state is failed, and makes canRetryPublish() true:
                // a SocialReins retry is always safe (frozen request, same Idempotency-Key).
                'publish_progress' => [
                    'driver' => SocialreinsConfig::DRIVER,
                    'state' => 'failed',
                    'options' => $options,
                    // A split post also keeps its PostSyncer reschedule checkpoint.
                    ...(is_array($split) ? ['split' => $split] + array_intersect_key($locked->publish_progress, ['reschedule' => true]) : []),
                ],
            ])->save();

            if ($telegramRequest?->state === TelegramPostRequest::FAILED) {
                $telegramRequest->forceFill([
                    'state' => TelegramPostRequest::APPROVED,
                    'error_message' => null,
                ])->save();
            }

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
