<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retires the single-sender access model (linked_telegram_user_id) now
     * that telegram_bot_links carries a real per-user link. Any existing
     * link is best-effort backfilled onto the workspace's team owner, the
     * only user identity a bare Telegram id can be attributed to; the
     * owner can immediately confirm/replace it via the normal /link flow.
     */
    public function up(): void
    {
        DB::table('telegram_bot_configs')
            ->whereNotNull('linked_telegram_user_id')
            ->orderBy('id')
            ->each(function (object $config): void {
                $workspace = DB::table('workspaces')->where('id', $config->workspace_id)->first();
                $team = $workspace !== null ? DB::table('teams')->where('id', $workspace->team_id)->first() : null;

                if ($team === null) {
                    return;
                }

                DB::table('telegram_bot_links')->insertOrIgnore([
                    'telegram_bot_config_id' => $config->id,
                    'user_id' => $team->owner_id,
                    'telegram_user_id' => $config->linked_telegram_user_id,
                    'telegram_username' => null,
                    'linked_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('telegram_bot_configs', function (Blueprint $table) {
            $table->dropColumn('linked_telegram_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_bot_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('linked_telegram_user_id')->nullable()->after('bot_username');
        });

        // Backfilled link rows aren't reverse-migrated into the dropped
        // column; the earlier single-sender model isn't restored.
    }
};
