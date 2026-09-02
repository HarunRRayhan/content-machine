<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('production')
            && ! config('app.telegram_cutover_ready')
        ) {
            throw new RuntimeException(
                'Telegram generation cutover is blocked. Set CM_TELEGRAM_CUTOVER_READY=true only after the old web fleet is drained and legacy outbound rows are reconciled.',
            );
        }

        $nullCount = DB::table('telegram_outbound_messages')
            ->whereNull('webhook_generation')
            ->count();

        if ($nullCount > 0) {
            throw new RuntimeException(
                "Cannot enforce generation-scoped Telegram outbound keys while {$nullCount} row(s) have no webhook generation. Reconcile them first.",
            );
        }

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
