<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Whoever messages the bot first (by their Telegram user id) is bound
     * as the only sender that gets captured; anyone else gets a "this bot
     * is private" reply instead of silently writing into the workspace's
     * Scratch Pad. There's no account-linking UI yet, this is the cheapest
     * real access-control boundary available given only a webhook slug
     * (already unguessable) stands between "knows the bot's @username" and
     * "can write into this workspace." Reset to null on disconnect
     * (DisconnectTelegramBotAction) so a fresh connect can bind a new
     * sender.
     */
    public function up(): void
    {
        Schema::table('telegram_bot_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('linked_telegram_user_id')->nullable()->after('bot_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telegram_bot_configs', function (Blueprint $table) {
            $table->dropColumn('linked_telegram_user_id');
        });
    }
};
