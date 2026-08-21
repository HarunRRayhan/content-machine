<?php

use App\Actions\Telegram\SyncTelegramBotCommandsAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * TelegramBotCommands::LIST gained /clearnotes; re-syncs Telegram's "/"
     * menu for every already-connected bot so it shows up without anyone
     * needing to disconnect/reconnect. See
     * 2026_08_21_120726_sync_commands_for_already_connected_telegram_bots.php
     * for why this needs re-running per command-list change rather than
     * being a one-time fix.
     */
    public function up(): void
    {
        app(SyncTelegramBotCommandsAction::class)->handle();
    }

    public function down(): void
    {
        // Nothing to reverse: this only calls an external API, it never
        // changes this app's own data.
    }
};
