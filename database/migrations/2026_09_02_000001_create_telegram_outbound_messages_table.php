<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_outbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telegram_bot_config_id')->constrained()->cascadeOnDelete();
            $table->uuid('webhook_generation')->nullable();
            $table->bigInteger('chat_id');
            $table->string('logical_key', 191);
            $table->jsonb('chunks');
            $table->unsignedInteger('next_chunk')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status', 20)->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('sent_at')->nullable()->index();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('discarded_at')->nullable();
            $table->timestampsTz();

            $table->unique(['telegram_bot_config_id', 'logical_key']);
            $table->index(['status', 'next_attempt_at', 'id']);
            $table->index(['telegram_bot_config_id', 'webhook_generation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_outbound_messages');
    }
};
