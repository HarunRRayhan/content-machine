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
 * Queue a SocialReins publish for a Post or Video. Every publish, retry and Telegram or MCP
 * publish comes through here.
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

        $options = array_intersect_key(
            array_filter($options, fn (mixed $value): bool => $value !== null),
            array_flip(['when', 'platforms', 'confirm_ask', 'telegram_request_id']),
        );

        try {
            $requestId = TelegramPublishRequests::idFrom($options);
        } catch (InvalidArgumentException) {
            throw $this->refuse(__('The Telegram publish request is invalid.'));
        }

        /** @var T $queued */
        $queued = DB::transaction(function () use ($subject, $workspace, $options, $requestId): Post|Video {
            // Same lock order as the settings save: workspace, then subject.
            $lockedWorkspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            $locked = $subject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();

            // A post PostSyncer published is history: publishing it here could send it twice.
            if (SocialreinsConfig::hasPostsyncerPost($locked)) {
                throw $this->refuse(__(SocialreinsConfig::POSTSYNCER_HISTORY_MESSAGE));
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

            // What SocialReins does not cover is published nowhere. The publish leaves it out
            // (PublishToSocialreinsAction) and the item says so.
            ['covered' => $covered, 'left_out' => $leftOut] = SocialreinsConfig::coverage($locked, $lockedWorkspace, $options);
            if ($covered === [] && $leftOut !== []) {
                throw $this->refuse(__('Nothing to publish: SocialReins does not cover :pairs.', [
                    'pairs' => implode(', ', $leftOut),
                ]));
            }

            // A publish that names its request is admitted only when the request is approved
            // (or failed, on a retry). The run that closes a request
            // keeps its id in the stored options. Only posts have Telegram requests.
            unset($options['telegram_request_id']);
            $telegramRequest = null;
            if ($locked instanceof Post) {
                $named = $requestId !== null;

                // A run that never settled (its worker died) left its request approved: keep it.
                // A failed one is found by admit() itself.
                $stored = $locked->publish_progress['options']['telegram_request_id'] ?? null;
                if (! $named && is_int($stored) && $locked->telegramPostRequests()->whereKey($stored)
                    ->where('state', TelegramPostRequest::APPROVED)->exists()) {
                    $requestId = $stored;
                }

                try {
                    $telegramRequest = TelegramPublishRequests::admit($locked, $requestId, $locked->publish_state === 'failed');
                } catch (ValidationException $exception) {
                    // The dashboard cannot name a request, and the post itself is approved: a
                    // request that cannot be adopted (bot disconnected or rotated, several
                    // failed ones) is left alone and the publish goes out without it.
                    if ($named) {
                        throw $exception;
                    }
                }
            }
            if ($telegramRequest !== null) {
                $options['telegram_request_id'] = $telegramRequest->id;
            }

            $locked->forceFill([
                'publish_state' => 'queued',
                'publish_error' => null,
                // `driver` marks a SocialReins run (a PostSyncer run had none). `state` is
                // read only once publish_state is failed, and makes canRetryPublish() true:
                // a SocialReins retry is always safe (frozen request, same Idempotency-Key).
                'publish_progress' => [
                    'driver' => SocialreinsConfig::DRIVER,
                    'state' => 'failed',
                    'options' => $options,
                    ...($leftOut !== [] ? ['left_out' => $leftOut] : []),
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
