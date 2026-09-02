<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('production', 'prod')
            && ! config('app.telegram_cutover_ready')
        ) {
            throw new RuntimeException(
                'Telegram generation cutover is blocked. Set CM_TELEGRAM_CUTOVER_READY=true only after the old web fleet is drained and legacy outbound rows are reconciled.',
            );
        }

        $nullChecks = [
            'bot config' => DB::table('telegram_bot_configs')->whereNull('webhook_generation')->count(),
            'update' => DB::table('telegram_updates')->whereNull('webhook_generation')->count(),
            'post request' => DB::table('telegram_post_requests')->whereNull('webhook_generation')->count(),
            'Telegram source capture' => DB::table('scratchpad_entries')
                ->where('source', 'telegram')
                ->whereNull('webhook_generation')
                ->count(),
            'outbound message' => DB::table('telegram_outbound_messages')->whereNull('webhook_generation')->count(),
        ];

        foreach ($nullChecks as $label => $count) {
            if ($count > 0) {
                throw new RuntimeException(
                    "Cannot enforce generation-scoped Telegram keys while {$count} {$label} row(s) have no webhook generation. Reconcile them first.",
                );
            }
        }

        $orphanedSources = DB::table('scratchpad_entries as entries')
            ->leftJoin('telegram_bot_configs as configs', 'configs.workspace_id', '=', 'entries.workspace_id')
            ->where('entries.source', 'telegram')
            ->whereNull('configs.id')
            ->count();

        if ($orphanedSources > 0) {
            throw new RuntimeException(
                "Cannot enforce generation-scoped Telegram keys while {$orphanedSources} Telegram source capture(s) have no bot config.",
            );
        }

        $orphanedRequests = DB::table('telegram_post_requests')
            ->where('state', 'generating')
            ->whereNull('source_scratchpad_entry_id')
            ->count();

        if ($orphanedRequests > 0) {
            throw new RuntimeException(
                "Cannot enforce generation-scoped Telegram keys while {$orphanedRequests} generating post request(s) have no source capture.",
            );
        }

        $unprocessedUpdates = DB::table('telegram_updates')
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->whereNull('discarded_at')
            ->count();

        if ($unprocessedUpdates > 0) {
            throw new RuntimeException(
                "Cannot enforce generation-scoped Telegram keys while {$unprocessedUpdates} Telegram update(s) are still unprocessed.",
            );
        }

        $generatingRequests = DB::table('telegram_post_requests')
            ->where('state', 'generating')
            ->count();

        if ($generatingRequests > 0) {
            throw new RuntimeException(
                "Cannot enforce generation-scoped Telegram keys while {$generatingRequests} Telegram post request(s) are generating.",
            );
        }

        $connectionOperations = DB::table('telegram_bot_configs')
            ->whereNotNull('connection_operation')
            ->count();

        if ($connectionOperations > 0) {
            throw new RuntimeException(
                "Cannot enforce generation-scoped Telegram keys while {$connectionOperations} Telegram connection operation(s) are still in progress.",
            );
        }

        $outboundInFlight = DB::table('telegram_outbound_messages')
            ->whereIn('status', ['pending', 'sending', 'uncertain'])
            ->count();

        if ($outboundInFlight > 0) {
            throw new RuntimeException(
                "Cannot enforce generation-scoped Telegram keys while {$outboundInFlight} outbound Telegram message(s) require reconciliation.",
            );
        }

        $duplicateKeys = DB::table('telegram_outbound_messages')
            ->select('telegram_bot_config_id', 'webhook_generation', 'logical_key')
            ->groupBy('telegram_bot_config_id', 'webhook_generation', 'logical_key')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicateKeys > 0) {
            throw new RuntimeException(
                "Cannot enforce generation-scoped Telegram keys while {$duplicateKeys} duplicate outbound logical key group(s) exist. Reconcile them first.",
            );
        }

        DB::statement('ALTER TABLE telegram_bot_configs ALTER COLUMN webhook_generation SET NOT NULL');
        DB::statement('ALTER TABLE telegram_updates ALTER COLUMN webhook_generation SET NOT NULL');
        DB::statement('ALTER TABLE telegram_post_requests ALTER COLUMN webhook_generation SET NOT NULL');
        DB::statement(
            'ALTER TABLE scratchpad_entries ADD CONSTRAINT telegram_source_webhook_generation_not_null CHECK (source <> \'telegram\' OR webhook_generation IS NOT NULL)',
        );

        Schema::table('telegram_outbound_messages', function (Blueprint $table): void {
            $table->unique(
                ['telegram_bot_config_id', 'webhook_generation', 'logical_key'],
                'telegram_outbound_generation_logical_key_unique',
            );
        });

        DB::statement(
            'ALTER TABLE telegram_outbound_messages ALTER COLUMN webhook_generation SET NOT NULL',
        );

        Schema::table('telegram_outbound_messages', function (Blueprint $table): void {
            $table->dropUnique(['telegram_bot_config_id', 'logical_key']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Telegram outbound generation cutover is forward-only; restore from a database backup instead of rolling it back.',
        );
    }
};
