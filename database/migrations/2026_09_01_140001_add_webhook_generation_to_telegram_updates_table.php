<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->uuid('webhook_generation')->nullable()->after('update_id');
        });

        DB::statement(
            'UPDATE telegram_updates '
            .'SET webhook_generation = telegram_bot_configs.webhook_generation '
            .'FROM telegram_bot_configs '
            .'WHERE telegram_updates.telegram_bot_config_id = telegram_bot_configs.id'
        );

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropUnique(['telegram_bot_config_id', 'update_id']);
            $table->unique(['telegram_bot_config_id', 'webhook_generation', 'update_id']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropUnique(['telegram_bot_config_id', 'webhook_generation', 'update_id']);
            $table->unique(['telegram_bot_config_id', 'update_id']);
            $table->dropColumn('webhook_generation');
        });
    }
};
