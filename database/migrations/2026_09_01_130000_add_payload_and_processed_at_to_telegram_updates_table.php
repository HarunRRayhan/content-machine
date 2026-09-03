<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->jsonb('payload')->nullable()->after('update_id');
            $table->timestampTz('processed_at')->nullable()->index()->after('payload');
        });

        // Rows created before the payload outbox existed cannot be replayed
        // safely because their raw Telegram update was never stored.
        DB::table('telegram_updates')->update(['processed_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropIndex(['processed_at']);
            $table->dropColumn(['payload', 'processed_at']);
        });
    }
};
