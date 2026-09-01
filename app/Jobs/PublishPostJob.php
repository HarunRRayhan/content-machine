<?php

namespace App\Jobs;

use App\Actions\Postsyncer\PublishPostAction;
use App\Models\Post;
use App\Models\TelegramPostRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 900;

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

    /**
     * Keep duplicate dispatches and visibility-timeout redeliveries from
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

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            report($exception);
        }

        $operationId = $this->operationId;
        $leaseId = $this->leaseId;

        // Legacy jobs have no durable claim identity. Their action path owns
        // failure recording; this hook must not infer ownership from the
        // serialized Post snapshot, which may now belong to a newer retry.
        if ($leaseId === null) {
            return;
        }

        DB::transaction(function () use ($exception, $operationId, $leaseId): void {
            $post = Post::query()
                ->whereKey($this->post->getKey())
                ->lockForUpdate()
                ->first();

            if ($post === null || $post->publish_state === 'succeeded') {
                return;
            }

            $progress = $post->publish_progress;
            if ($operationId !== null
                && (! is_array($progress) || ($progress['operation_id'] ?? null) !== $operationId)
            ) {
                // A newer retry owns the row. The old worker must not overwrite
                // its state or Telegram request.
                return;
            }

            if ($post->publish_lease_id !== $leaseId) {
                return;
            }

            $unknownOutcome = false;
            if (is_array($progress)) {
                $current = $progress['current'] ?? null;
                $unknownOutcome = ($current !== null
                    && (! is_array($current) || ($current['phase'] ?? null) !== 'uploading'))
                    || ($progress['state'] ?? null) === 'uncertain';
                $progress['state'] = $unknownOutcome ? 'uncertain' : 'failed';
            }

            $error = $exception?->getMessage() ?? 'The PostSyncer publish job failed.';
            if ($unknownOutcome) {
                $error = 'PostSyncer create outcome is uncertain. Reconcile PostSyncer before retrying. '.$error;
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
        });
    }

    public function handle(PublishPostAction $action): void
    {
        if ($this->operationId === null && $this->leaseId === null) {
            // A legacy serialized job may be redelivered after a newer
            // operation has already written progress. It cannot prove that
            // its stale options belong to that operation, so let the durable
            // progress owner continue instead.
            if (is_array($this->post->fresh()?->publish_progress)) {
                return;
            }

            $action->handle($this->post, $this->options);

            return;
        }

        $action->handle($this->post, $this->options, $this->operationId, $this->leaseId);
    }
}
