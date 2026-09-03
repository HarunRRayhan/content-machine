<?php

use App\Actions\Telegram\SyncTelegramBotCommandsAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * TelegramBotCommands::LIST gained the post drafting and approval
     * commands. Re-sync already-connected bots so their slash menu matches
     * the handler without requiring a reconnect.
     */
    public function up(): void
    {
        app(SyncTelegramBotCommandsAction::class)->handle();
    }

    public function down(): void
    {
        // The external Telegram command menu has no transactional rollback.
    }
};
