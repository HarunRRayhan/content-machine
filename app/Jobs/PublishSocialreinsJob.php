<?php

namespace App\Jobs;

use App\Actions\Socialreins\PublishToSocialreinsAction;
use App\Models\Post;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends a Post's or Video's SocialReins post groups. It runs on the same worker as the
 * PostSyncer jobs because the planner builds signed URLs for attachments that live on the
 * volume only that worker sees. One try: the action itself resends frozen requests with
 * the same Idempotency-Key when asked to retry, so a queue retry would add nothing.
 */
class PublishSocialreinsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 900;

    public const LEASE_SECONDS = 1020;

    public int $timeout = self::TIMEOUT_SECONDS;

    public int $tries = 1;

    /** @param  array{when?: string|null, platforms?: list<string>, confirm_ask?: bool}  $options */
    public function __construct(
        public readonly Post|Video $subject,
        public readonly array $options = [],
    ) {
        $this->onConnection('postsyncer')->onQueue(
            (string) config('queue.connections.postsyncer.queue', 'postsyncer'),
        );
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        $kind = $this->subject instanceof Video ? 'video' : 'post';

        return [
            (new WithoutOverlapping("socialreins:{$kind}:".$this->subject->getKey(), 60, self::LEASE_SECONDS))->shared(),
        ];
    }

    public function handle(PublishToSocialreinsAction $action): void
    {
        $action->handle($this->subject, $this->options);
    }

    public function failed(?Throwable $exception): void
    {
        app(PublishToSocialreinsAction::class)->markFailed(
            $this->subject,
            $exception?->getMessage() ?? 'The SocialReins publish job failed.',
        );

        if ($exception !== null) {
            report($exception);
        }
    }
}
