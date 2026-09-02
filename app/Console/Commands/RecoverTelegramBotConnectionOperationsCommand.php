<?php

namespace App\Console\Commands;

use App\Actions\Telegram\CompleteTelegramBotConnectionAction;
use App\Models\TelegramBotConfig;
use App\Support\Telegram\TelegramBotIdentityLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Throwable;

class RecoverTelegramBotConnectionOperationsCommand extends Command
{
    protected $signature = 'telegram:recover-connection-operations {--limit=25 : Maximum connection operations to inspect}';

    protected $description = 'Retry durable Telegram connect, webhook cleanup, and disconnect operations';

    public function handle(CompleteTelegramBotConnectionAction $completion): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $completed = 0;
        $failed = 0;

        TelegramBotConfig::query()
            ->whereNotNull('connection_operation')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'workspace_id'])
            ->each(function (TelegramBotConfig $config) use ($completion, &$completed, &$failed): void {
                $lock = TelegramBotIdentityLock::forWorkspace($config->workspace_id);

                try {
                    $lock->block(1);
                } catch (Throwable $exception) {
                    if ($exception instanceof LockTimeoutException) {
                        return;
                    }

                    report($exception);
                    $failed++;

                    return;
                }

                try {
                    if ($completion->handle($config->id)) {
                        $completed++;
                    } else {
                        $failed++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                } finally {
                    $lock->release();
                }
            });

        $this->info("Recovered {$completed} Telegram connection operation(s).");

        if ($failed > 0) {
            $this->warn("{$failed} Telegram connection operation(s) still require recovery.");
        }

        return self::SUCCESS;
    }
}
