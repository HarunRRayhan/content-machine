<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_post_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('telegram_bot_config_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('source_scratchpad_entry_id')->nullable()->constrained('scratchpad_entries')->nullOnDelete();
            $table->unsignedBigInteger('telegram_user_id');
            // Chat ids can be negative for Telegram group chats, so this is signed.
            $table->bigInteger('telegram_chat_id');
            $table->string('state')->default('awaiting_input')->index();
            $table->text('instruction')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['telegram_bot_config_id', 'telegram_user_id', 'telegram_chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_post_requests');
    }
};
