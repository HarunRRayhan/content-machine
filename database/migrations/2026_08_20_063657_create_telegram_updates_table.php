<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The idempotency guard for the webhook: Telegram redelivers an update
     * whenever it doesn't get a fast 2xx, so TelegramWebhookController
     * firstOrCreate()s a row here per (telegram_bot_config_id, update_id)
     * and only dispatches ProcessTelegramUpdateJob the first time a given
     * update_id is seen. update_id is only unique per bot, not globally,
     * hence the composite unique key rather than a unique on update_id
     * alone.
     */
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_config_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('update_id');
            $table->timestampsTz();

            $table->unique(['telegram_bot_config_id', 'update_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
