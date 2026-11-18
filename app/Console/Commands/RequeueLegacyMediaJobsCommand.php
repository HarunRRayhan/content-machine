<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move jobs serialized before queue routing was added to the workers that
 * have the uploads volume. Live reservations are left alone; an expired
 * reservation is released and moved while holding the row lock so the
 * default worker cannot claim it in the same race.
 */
class RequeueLegacyMediaJobsCommand extends Command
{
    protected $signature = 'cm:requeue-legacy-media-jobs';

    protected $description = 'Move legacy media jobs to the volume-backed queues';

    public function handle(): int
    {
        if (! Schema::hasTable('jobs')) {
            $this->info('No queue table found; nothing to requeue.');

            return self::SUCCESS;
        }

        $routes = [
            'App\\Jobs\\ProcessTelegramUpdateJob' => 'scratchpad',
            'App\\Jobs\\TranscribeVoiceNoteJob' => 'scratchpad',
            'App\\Jobs\\GenerateTelegramPostJob' => 'scratchpad',
            'App\\Jobs\\PublishPostJob' => (string) config('queue.connections.postsyncer.queue', 'postsyncer'),
            'App\\Jobs\\PublishVideoJob' => (string) config('queue.connections.postsyncer.queue', 'postsyncer'),
        ];
        $requeued = 0;
        $expiredAt = time() - (int) config('queue.connections.database.retry_after', 960);

        DB::transaction(function () use ($routes, $expiredAt, &$requeued): void {
            DB::table('jobs')
                ->where(function ($query) use ($expiredAt): void {
                    $query->whereNull('reserved_at')
                        ->orWhere('reserved_at', '<=', $expiredAt);
                })
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get(['id', 'queue', 'payload', 'reserved_at'])
                ->each(function (object $job) use ($routes, $expiredAt, &$requeued): void {
                    /** @var mixed $decodedPayload */
                    $decodedPayload = json_decode((string) $job->payload, true);
                    $jobClass = is_array($decodedPayload) && is_string($decodedPayload['displayName'] ?? null)
                        ? $decodedPayload['displayName']
                        : null;
                    $targetQueue = $jobClass !== null ? ($routes[$jobClass] ?? null) : null;

                    if ($targetQueue === null || $job->queue === $targetQueue) {
                        return;
                    }

                    $attributes = ['queue' => $targetQueue];
                    if ($job->reserved_at !== null && (int) $job->reserved_at <= $expiredAt) {
                        $attributes['reserved_at'] = null;
                        $attributes['available_at'] = time();
                    }

                    $requeued += DB::table('jobs')
                        ->where('id', $job->id)
                        ->update($attributes);
                });
        });

        $this->info("Requeued {$requeued} legacy media job(s).");

        return self::SUCCESS;
    }
}
