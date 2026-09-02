<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        // New webhook writers use the generation-scoped fence; keeping this
        // global index would discard the first update after a bot identity
        // rotation.
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropUnique(['telegram_bot_config_id', 'update_id']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Telegram update generation cutover is forward-only; restore from a database backup instead of recreating the legacy unique index.',
        );
    }
};
