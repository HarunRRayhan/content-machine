<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_bot_configs', function (Blueprint $table): void {
            $table->uuid('webhook_generation')->nullable()->after('webhook_slug');
        });

        DB::table('telegram_bot_configs')
            ->whereNull('webhook_generation')
            ->orderBy('id')
            ->get(['id'])
            ->each(fn (object $config): int => DB::table('telegram_bot_configs')
                ->where('id', $config->id)
                ->update(['webhook_generation' => (string) Str::uuid()]));
    }

    public function down(): void
    {
        Schema::table('telegram_bot_configs', function (Blueprint $table): void {
            $table->dropColumn('webhook_generation');
        });
    }
};
