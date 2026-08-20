<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per workspace (unique workspace_id). There's no `enabled`
     * column: "enabled" is simply `bot_token !== null`, see
     * TelegramBotConfig::isConnected(). `webhook_secret` and
     * `webhook_slug` are generated once on first successful connect and
     * then kept stable across disconnect/reconnect, so a workspace's
     * webhook URL doesn't change every time the bot token is rotated;
     * `webhook_slug` is the unguessable path segment
     * (docs/adr/0001-webhook-not-polling.md), `webhook_secret` is the
     * value later sent as X-Telegram-Bot-Api-Secret-Token. Neither is
     * used yet, they're provisioned here so the receiving controller
     * (a separate slice) needs no further schema change.
     */
    public function up(): void
    {
        Schema::create('telegram_bot_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('bot_token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_slug')->nullable()->unique();
            $table->string('bot_username')->nullable();
            $table->timestampTz('connected_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_configs');
    }
};
