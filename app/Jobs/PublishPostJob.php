<?php

namespace App\Jobs;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
use App\Models\TelegramPostRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PublishPostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 900;

    public const UNIQUE_FOR_SECONDS = 3600;

    public const LEASE_SECONDS = 1020;

    public const OVERLAP_EXPIRES_AFTER_SECONDS = 1020;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * These fields were added after publish jobs could already be persisted in
     * the queue. Explicit defaults let old serialized jobs deserialize safely.
     */
    public ?string $operationId = null;

    public ?string $leaseId = null;

    /**
     * @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool, telegram_request_id?: int}  $options
     */
    public function __construct(
        public readonly Post $post,
        public readonly array $options = [],
        public readonly ?string $runToken = null,
        ?string $operationId = null,
        ?string $leaseId = null,
    ) {
        $this->operationId = $operationId;
        $this->leaseId = $leaseId;
        // Post covers live on the scratchpad uploads volume, mounted only on
        // cm-web. cm-worker's default queue cannot see those files, so
        // MediaUrlResolver skipped attachments and published text-only (P-57).
        // Run on a queue that only the dedicated PostSyncer worker consumes.
        $this->onConnection('postsyncer')->onQueue(
            (string) config('queue.connections.postsyncer.queue', 'postsyncer'),
        );
    }

    private ?string $effectiveRunToken = null;

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
        return 'post:'.$this->post->getKey().':run:'.($this->runTokenOrNull() ?? 'legacy');
    }

    public function uniqueFor(): int
    {
        return self::UNIQUE_FOR_SECONDS;
    }

    public function failed(?Throwable $exception): void
    {
        $recorded = DB::transaction(function () use ($exception): bool {
            $post = Post::query()
                ->whereKey($this->post->getKey())
                ->lockForUpdate()
                ->first();

            $runToken = $this->effectiveRunTokenOrNull() ?? $this->runTokenOrNull();

            if ($post === null
                || $post->publish_state === 'succeeded'
                || ($this->leaseId === null && ! $this->isCurrentRun($post, $runToken))) {
                return false;
            }

            $progress = $post->publish_progress;
            if ($this->operationId !== null
                && (! is_array($progress) || ($progress['operation_id'] ?? null) !== $this->operationId)
            ) {
                return false;
            }

            if ($this->leaseId !== null && $post->publish_lease_id !== $this->leaseId) {
                return false;
            }

            if ($this->leaseId !== null
                && ($post->publish_claimed_at === null
                    || $post->publish_claimed_at->isBefore(now()->subSeconds(self::LEASE_SECONDS)))) {
                return false;
            }

            $unknownOutcome = false;
            if (is_array($progress)) {
                $current = $progress['current'] ?? null;
                $unknownOutcome = ($progress['state'] ?? null) === 'uncertain'
                    || ($current !== null
                        && (! is_array($current) || ($current['phase'] ?? null) !== 'retryable'));
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
                'publish_claimed_at' => null,
                'publish_lease_id' => null,
            ])->save();

            /** @var mixed $requestId */
            $requestId = $this->options['telegram_request_id'] ?? null;
            if (is_int($requestId) || (is_string($requestId) && ctype_digit($requestId))) {
                TelegramPostRequest::query()
                    ->whereKey((int) $requestId)
                    ->where('post_id', $post->id)
                    ->whereIn('state', [
                        TelegramPostRequest::APPROVED,
                        TelegramPostRequest::FAILED,
                    ])
                    ->update([
                        'state' => TelegramPostRequest::FAILED,
                        'error_message' => $error,
                    ]);
            }

            return true;
        });

        if ($recorded && $exception !== null) {
            report($exception);
        }
    }

    public function handle(PublishPostAction $action): void
    {
        $post = $this->post->fresh();
        $runToken = $this->runTokenOrNull() ?? $this->effectiveRunTokenOrNull();

        if ($runToken === null && $post !== null) {
            $storedToken = is_array($post->publish_progress)
                ? ($post->publish_progress['run_token'] ?? null)
                : null;
            $runToken = is_string($storedToken) && trim($storedToken) !== ''
                ? $storedToken
                : null;
        }

        if ($post !== null && ! $this->isCurrentRun($post, $runToken)) {
            return;
        }

        $runToken ??= (string) Str::uuid();
        $this->effectiveRunToken = $runToken;

        if ($this->operationId === null && $this->leaseId === null) {
            $action->handle($this->post, $this->options, $runToken);

            return;
        }

        $action->handle(
            $this->post,
            $this->options,
            $runToken,
            $this->operationId,
            $this->leaseId,
        );
    }

    private function isCurrentRun(Post $post, ?string $runToken = null): bool
    {
        $progress = $post->publish_progress;
        $storedToken = is_array($progress) ? ($progress['run_token'] ?? null) : null;
        $runToken ??= $this->runTokenOrNull();

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
