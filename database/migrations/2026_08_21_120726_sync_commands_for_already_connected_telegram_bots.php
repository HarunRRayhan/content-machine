<?php

use App\Support\Telegram\TelegramBotCommands;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * setMyCommands only ever ran inside ConnectTelegramBotAction, so any
     * bot connected before that call existed (or before the command list
     * last changed) never got Telegram's "/" command menu registered.
     * One-time, best-effort sync for every currently-connected bot;
     * a failure for one workspace doesn't stop the rest or fail the
     * migration, same as setMyCommands is best-effort everywhere else.
     */
    public function up(): void
    {
        $client = app(TelegramClientContract::class);

        DB::table('telegram_bot_configs')
            ->whereNotNull('bot_token')
            ->select('bot_token')
            ->orderBy('id')
            ->each(function (object $config) use ($client): void {
                try {
                    $client->setMyCommands(Crypt::decryptString($config->bot_token), TelegramBotCommands::LIST);
                } catch (Throwable) {
                    // A single unreadable token (or unreachable Telegram)
                    // doesn't stop the rest of the sync, same best-effort
                    // spirit as setMyCommands itself already has.
                }
            });
    }

    public function down(): void
    {
        // Nothing to reverse: this only calls an external API, it never
        // changes this app's own data.
    }
};
