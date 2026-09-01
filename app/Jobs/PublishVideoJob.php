<?php

namespace App\Jobs;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishVideoJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 180;

    public const UNIQUE_FOR_SECONDS = 3600;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 240;

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
        public readonly Video $video,
        public readonly array $options = [],
        public readonly ?string $runToken = null,
    ) {
        $this->onConnection('postsyncer')->onQueue(
            (string) config('queue.connections.postsyncer.queue', 'postsyncer'),
        );
    }

    private ?string $effectiveRunToken = null;

    /**
     * Keep duplicate deliveries and visibility-timeout redeliveries from
     * running beside the original publish attempt.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(
                'postsyncer:video:'.$this->video->getKey(),
                60,
                self::OVERLAP_EXPIRES_AFTER_SECONDS,
            ))->shared(),
        ];
    }

    public function uniqueId(): string
    {
        return 'video:'.$this->video->getKey().':run:'.($this->runTokenOrNull() ?? 'legacy');
    }

    public function uniqueFor(): int
    {
        return self::UNIQUE_FOR_SECONDS;
    }

    public function failed(?Throwable $exception): void
    {
        $video = $this->video->fresh();

        if ($video === null) {
            return;
        }

        $recorded = DB::transaction(function () use ($video, $exception): bool {
            $lockedVideo = Video::query()
                ->whereKey($video->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedVideo === null
                || ! $this->isCurrentRun($lockedVideo)
                || $lockedVideo->publish_state === 'succeeded') {
                return false;
            }

            $progress = $lockedVideo->publish_progress;

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

            $lockedVideo->forceFill([
                'publish_state' => 'failed',
                'publish_error' => $error,
                'publish_progress' => $progress,
            ])->save();

            return true;
        });

        if ($recorded && $exception !== null) {
            report($exception);
        }
    }

    public function handle(PublishVideoAction $action): void
    {
        $video = $this->video->fresh();
        $runToken = $this->runTokenOrNull();
        if ($runToken === null && $video !== null) {
            $storedToken = is_array($video->publish_progress)
                ? ($video->publish_progress['run_token'] ?? null)
                : null;
            $runToken = is_string($storedToken) && trim($storedToken) !== ''
                ? $storedToken
                : null;
        }

        if ($video !== null && ! $this->isCurrentRun($video, $runToken)) {
            return;
        }

        if ($runToken === null) {
            try {
                $action->handle($this->video, $this->options);
            } finally {
                $progress = $this->video->fresh()?->publish_progress;
                $this->effectiveRunToken = is_array($progress)
                    && is_string($progress['run_token'] ?? null)
                    ? $progress['run_token']
                    : null;
            }

            return;
        }

        $this->effectiveRunToken = $runToken;
        $action->handle($this->video, $this->options, $runToken);
    }

    private function isCurrentRun(Video $video, ?string $runToken = null): bool
    {
        $progress = $video->publish_progress;
        $storedToken = is_array($progress) ? ($progress['run_token'] ?? null) : null;

        $runToken ??= $this->effectiveRunTokenOrNull() ?? $this->runTokenOrNull();

        if ($runToken === null) {
            return ! is_string($storedToken) || trim($storedToken) === '';
        }

        return is_string($storedToken) && hash_equals($storedToken, $runToken);
    }

    private function runTokenOrNull(): ?string
    {
        return isset($this->runToken) ? $this->runToken : null;
    }

    private function effectiveRunTokenOrNull(): ?string
    {
        return isset($this->effectiveRunToken) ? $this->effectiveRunToken : null;
    }
}
