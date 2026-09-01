<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use Illuminate\Console\Command;

class DispatchPendingTelegramUpdatesCommand extends Command
{
    protected $signature = 'telegram:dispatch-pending-updates {--limit=100 : Maximum pending updates to enqueue}';

    protected $description = 'Enqueue Telegram webhook updates that have not finished processing';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dispatched = 0;

        TelegramUpdate::query()
            ->whereNull('processed_at')
            ->whereNotNull('payload')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (TelegramUpdate $update) use (&$dispatched): void {
                if (! is_array($update->payload)) {
                    return;
                }

                ProcessTelegramUpdateJob::dispatch(
                    $update->telegram_bot_config_id,
                    $update->payload,
                    $update->webhook_generation,
                );
                $dispatched++;
            });

        $this->info("Dispatched {$dispatched} pending Telegram update(s).");

        return self::SUCCESS;
    }
}
