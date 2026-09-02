<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scratchpad_entries', function (Blueprint $table): void {
            $table->string('telegram_update_key', 64)->nullable()->unique()->after('source');
        });

        Schema::table('telegram_post_requests', function (Blueprint $table): void {
            $table->string('telegram_update_key', 64)->nullable()->unique()->after('telegram_chat_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_post_requests', function (Blueprint $table): void {
            $table->dropUnique(['telegram_update_key']);
            $table->dropColumn('telegram_update_key');
        });

        Schema::table('scratchpad_entries', function (Blueprint $table): void {
            $table->dropUnique(['telegram_update_key']);
            $table->dropColumn('telegram_update_key');
        });
    }
};
