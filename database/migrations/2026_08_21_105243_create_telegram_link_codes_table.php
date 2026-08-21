<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short-lived, single-use code a team member generates from the
     * dashboard (GenerateTelegramLinkCodeAction) and then sends to the bot
     * as `/link CODE` to prove which app user they are (LinkTelegramAccountAction).
     * `consumed_at` rather than deleting on use, so a reused/expired code
     * fails with a clear reason instead of a generic "not found".
     */
    public function up(): void
    {
        Schema::create('telegram_link_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_bot_config_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_codes');
    }
};
