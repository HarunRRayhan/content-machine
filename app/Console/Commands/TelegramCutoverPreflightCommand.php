<?php

namespace App\Console\Commands;

use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Models\TelegramPostRequest;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Throwable;

class TelegramCutoverPreflightCommand extends Command
{
    protected $signature = 'telegram:cutover-preflight
        {--require-fleet-drained : Require the old web fleet drain attestation}
        {--verify-remote-webhooks : Check Telegram webhook URLs and pending counts}';

    protected $description = 'Check Telegram generation cutover prerequisites without changing data';

    public function handle(TelegramClientContract $client): int
    {
        try {
            $checks = DB::transaction(function (): array {
                // All hardened Telegram writers lock workspace before config.
                // Taking these locks makes the local snapshot atomic instead
                // of allowing a request to arrive between individual counts.
                DB::table('workspaces')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                TelegramBotConfig::query()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);

                return [
                    'bot configs with a null webhook generation' => DB::table('telegram_bot_configs')
                        ->whereNull('webhook_generation')
                        ->count(),
                    'updates with a null webhook generation' => DB::table('telegram_updates')
                        ->whereNull('webhook_generation')
                        ->count(),
                    'post requests with a null webhook generation' => DB::table('telegram_post_requests')
                        ->whereNull('webhook_generation')
                        ->count(),
                    'Telegram source captures with a null webhook generation' => DB::table('scratchpad_entries')
                        ->where('source', 'telegram')
                        ->whereNull('webhook_generation')
                        ->count(),
                    'outbound messages with a null webhook generation' => DB::table('telegram_outbound_messages')
                        ->whereNull('webhook_generation')
                        ->count(),
                    'duplicate generation-scoped outbound logical keys' => DB::table('telegram_outbound_messages')
                        ->select('telegram_bot_config_id', 'webhook_generation', 'logical_key')
                        ->groupBy('telegram_bot_config_id', 'webhook_generation', 'logical_key')
                        ->havingRaw('COUNT(*) > 1')
                        ->count(),
                    'Telegram source captures without a bot config' => DB::table('scratchpad_entries as entries')
                        ->leftJoin('telegram_bot_configs as configs', 'configs.workspace_id', '=', 'entries.workspace_id')
                        ->where('entries.source', 'telegram')
                        ->whereNull('configs.id')
                        ->count(),
                    'generating post requests without a source capture' => DB::table('telegram_post_requests')
                        ->where('state', TelegramPostRequest::GENERATING)
                        ->whereNull('source_scratchpad_entry_id')
                        ->count(),
                    'unprocessed Telegram updates' => DB::table('telegram_updates')
                        ->whereNull('processed_at')
                        ->whereNull('failed_at')
                        ->whereNull('discarded_at')
                        ->count(),
                    'queued Telegram update jobs' => DB::table('jobs')
                        ->where('payload', 'like', '%ProcessTelegramUpdateJob%')
                        ->count(),
                    'generating Telegram post requests' => DB::table('telegram_post_requests')
                        ->where('state', TelegramPostRequest::GENERATING)
                        ->count(),
                    'outbound messages requiring reconciliation' => DB::table('telegram_outbound_messages')
                        ->whereIn('status', [
                            TelegramOutboundMessage::PENDING,
                            TelegramOutboundMessage::SENDING,
                            TelegramOutboundMessage::UNCERTAIN,
                        ])
                        ->count(),
                    'Telegram connection operations still in progress' => DB::table('telegram_bot_configs')
                        ->whereNotNull('connection_operation')
                        ->count(),
                ];
            });

            $checks['old Telegram web fleet drain attested'] = $this->option('require-fleet-drained')
                ? ((bool) config('app.telegram_old_web_fleet_drained') ? 0 : 1)
                : 0;

            if ($this->option('verify-remote-webhooks')) {
                $checks['remote Telegram webhooks match the current URL with no pending updates'] = $this->verifyRemoteWebhooks($client);
            }

            $failed = 0;
            foreach ($checks as $label => $count) {
                $status = $count === 0 ? 'PASS' : 'FAIL';
                $this->line("{$status} {$label}: {$count}");
                $failed += $count > 0 ? 1 : 0;
            }

            if ($failed > 0) {
                $this->error('Telegram cutover preflight failed. No data was changed.');

                return self::FAILURE;
            }

            $this->info('Telegram cutover preflight passed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Could not run Telegram cutover preflight: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function verifyRemoteWebhooks(TelegramClientContract $client): int
    {
        $failed = 0;

        TelegramBotConfig::query()
            ->whereNotNull('bot_token')
            ->whereNotNull('webhook_slug')
            ->orderBy('id')
            ->get()
            ->each(function (TelegramBotConfig $config) use ($client, &$failed): void {
                $info = $client->getWebhookInfo((string) $config->bot_token);
                $expectedUrl = URL::route('telegram.webhook', ['slug' => $config->webhook_slug]);

                if ($info->successful
                    && $info->url === $expectedUrl
                    && $info->pendingUpdateCount === 0
                ) {
                    return;
                }

                $failed++;
                $reason = $info->successful
                    ? 'URL or pending update count does not match'
                    : ($info->error ?? 'Telegram webhook inspection failed');
                $this->line("FAIL remote webhook for bot config {$config->id}: {$reason}");
            });

        return $failed;
    }
}
