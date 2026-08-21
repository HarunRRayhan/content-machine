<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in, off by default: a linked member's default (uncommanded) text
     * message keeps going to the Scratch Pad exactly as before until a
     * workspace explicitly turns this on. When on, GenerateTelegramChatReplyAction
     * is tried first; a message stays capture-only regardless of this flag
     * via /note, and a photo/voice/link message is never affected by it.
     */
    public function up(): void
    {
        Schema::table('telegram_bot_configs', function (Blueprint $table) {
            $table->boolean('ai_chat_enabled')->default(false)->after('bot_username');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_bot_configs', function (Blueprint $table) {
            $table->dropColumn('ai_chat_enabled');
        });
    }
};
