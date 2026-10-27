<?php

use App\Actions\Telegram\SyncTelegramBotCommandsAction;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The /notes menu description now says it counts and lists active notes.
     * Re-sync already-connected bots so their slash menu shows it.
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
