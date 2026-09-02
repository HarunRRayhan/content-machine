<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scratchpad_entries', function (Blueprint $table): void {
            $table->uuid('webhook_generation')->nullable()->after('telegram_update_key');
            $table->index(['workspace_id', 'webhook_generation']);
        });

        Schema::table('telegram_post_requests', function (Blueprint $table): void {
            $table->uuid('webhook_generation')->nullable()->after('telegram_update_key');
            $table->index(['telegram_bot_config_id', 'webhook_generation']);
        });

        DB::statement(
            'UPDATE scratchpad_entries '
            .'SET webhook_generation = telegram_bot_configs.webhook_generation '
            .'FROM telegram_bot_configs '
            .'WHERE scratchpad_entries.source = ? '
            .'AND scratchpad_entries.workspace_id = telegram_bot_configs.workspace_id',
            ['telegram'],
        );

        DB::statement(
            'UPDATE telegram_post_requests '
            .'SET webhook_generation = telegram_bot_configs.webhook_generation '
            .'FROM telegram_bot_configs '
            .'WHERE telegram_post_requests.telegram_bot_config_id = telegram_bot_configs.id',
        );
    }

    public function down(): void
    {
        Schema::table('telegram_post_requests', function (Blueprint $table): void {
            $table->dropIndex(['telegram_bot_config_id', 'webhook_generation']);
            $table->dropColumn('webhook_generation');
        });

        Schema::table('scratchpad_entries', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'webhook_generation']);
            $table->dropColumn('webhook_generation');
        });
    }
};
