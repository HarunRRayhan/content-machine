<?php

use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->boolean('legacy_replayable')->default(false)->after('processed_at');
        });

        /** @var array<string, array{config_id: int, update_id: int}> $references */
        $references = [];

        // The payload migration marked every pre-existing update as processed.
        // Only a legacy job that was actually present in the queue at that
        // migration boundary may reopen one of those rows. Do not derive this
        // permission later from a matching update payload: a delayed job can
        // otherwise replay a destructive command that already ran.
        if (Schema::hasTable('jobs')) {
            DB::table('jobs')
                ->select('payload')
                ->orderBy('id')
                ->cursor()
                ->each(function (object $row) use (&$references): void {
                    $reference = $this->legacyJobReference($row->payload ?? null);

                    if ($reference === null) {
                        return;
                    }

                    $references[$reference['config_id'].':'.$reference['update_id']] = $reference;
                });
        }

        foreach ($references as $reference) {
            // Before the generation cutover there can be only one row for a
            // bot/update pair. Select the oldest row explicitly so a legacy
            // job cannot authorize a later generation's same-numbered update.
            $update = DB::table('telegram_updates')
                ->where('telegram_bot_config_id', $reference['config_id'])
                ->where('update_id', $reference['update_id'])
                ->orderBy('id')
                ->first(['id']);

            if ($update === null) {
                continue;
            }

            DB::table('telegram_updates')
                ->where('id', $update->id)
                ->update(['legacy_replayable' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropColumn('legacy_replayable');
        });
    }

    /**
     * Read only the trusted shape Laravel stores for a queued job. The
     * allow-list prevents arbitrary classes in a corrupted queue payload from
     * being instantiated while the migration inspects it.
     *
     * @return array{config_id: int, update_id: int}|null
     */
    private function legacyJobReference(mixed $payload): ?array
    {
        if (! is_string($payload)) {
            return null;
        }

        try {
            /** @var mixed $envelope */
            $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        $command = is_array($envelope)
            && is_array($envelope['data'] ?? null)
            ? ($envelope['data']['command'] ?? null)
            : null;

        if (! is_string($command)) {
            return null;
        }

        try {
            /** @var mixed $job */
            $job = @unserialize($command, ['allowed_classes' => [ProcessTelegramUpdateJob::class]]);

            if (! $job instanceof ProcessTelegramUpdateJob
                || $job->webhookGeneration !== null
                || $job->dispatchLeaseId !== null
                || $job->telegramBotConfigId < 1
            ) {
                return null;
            }

            $updateId = $job->update['update_id'] ?? null;
            if (is_string($updateId) && ctype_digit($updateId)) {
                $updateId = (int) $updateId;
            }

            if (! is_int($updateId) || $updateId < 0) {
                return null;
            }

            return [
                'config_id' => $job->telegramBotConfigId,
                'update_id' => $updateId,
            ];
        } catch (Throwable) {
            return null;
        }
    }
};
