<?php

namespace App\Jobs;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PublishPostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 900;

    public const UNIQUE_FOR_SECONDS = 3600;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 1020;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options
     */
    public function __construct(
        public readonly Post $post,
        public readonly array $options = [],
    ) {
        // Post covers live on the scratchpad uploads volume, mounted only on
        // cm-web. cm-worker's default queue cannot see those files, so
        // MediaUrlResolver skipped attachments and published text-only (P-57).
        // Run on a queue that only the dedicated PostSyncer worker consumes.
        $this->onConnection('postsyncer')->onQueue(
            (string) config('queue.connections.postsyncer.queue', 'postsyncer'),
        );
    }

    /**
     * Keep a duplicate dispatch or a visibility-timeout redelivery from
     * running beside the original publish attempt.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'postsyncer:post:'.$this->post->getKey(),
                60,
                self::OVERLAP_EXPIRES_AFTER_SECONDS,
            ))->shared(),
        ];
    }

    public function uniqueId(): string
    {
        return 'post:'.$this->post->getKey();
    }

    public function uniqueFor(): int
    {
        return self::UNIQUE_FOR_SECONDS;
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $post = $this->post->fresh();

        if ($post === null || $post->publish_state === 'succeeded') {
            return;
        }

        $progress = $post->publish_progress;
        $unknownOutcome = false;
        if (is_array($progress)) {
            $current = $progress['current'] ?? null;
            $unknownOutcome = $current !== null || ($progress['state'] ?? null) === 'uncertain';
            $progress['state'] = $unknownOutcome ? 'uncertain' : 'failed';
        }

        $error = $exception?->getMessage()
            ?? 'The PostSyncer publish job failed.';
        if ($unknownOutcome) {
            $error = 'PostSyncer create outcome is uncertain. Reconcile PostSyncer before retrying. '
                .$error;
        }

        $post->forceFill([
            'publish_state' => 'failed',
            'publish_error' => $error,
            'publish_progress' => $progress,
        ])->save();
    }

    public function handle(PublishPostAction $action): void
    {
        $action->handle($this->post, $this->options);
    }
}
