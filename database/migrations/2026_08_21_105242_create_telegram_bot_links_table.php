<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per (bot, app user) pairing: the bot itself is connected
     * once by whoever configures TelegramBotConfig, but every team member
     * who wants the bot to answer them links their own Telegram account
     * separately (see LinkTelegramAccountAction), so several people can
     * share one bot without impersonating each other. A user can only
     * hold one link per bot (unique telegram_bot_config_id+user_id) and a
     * given Telegram account can only be linked to one app user per bot
     * (unique telegram_bot_config_id+telegram_user_id).
     */
    public function up(): void
    {
        Schema::create('telegram_bot_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_config_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('telegram_user_id');
            $table->string('telegram_username')->nullable();
            $table->timestampTz('linked_at');
            $table->timestampsTz();

            $table->unique(['telegram_bot_config_id', 'user_id']);
            $table->unique(['telegram_bot_config_id', 'telegram_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_links');
    }
};
