<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            // Laravel's enum() on Postgres is a CHECK constraint, not a native type.
            DB::statement('alter table ideas drop constraint if exists ideas_trend_check');
            DB::statement(
                "alter table ideas add constraint ideas_trend_check check (trend is null or trend in ('evergreen', 'seasonal', 'short-trend'))"
            );

            return;
        }

        Schema::table('ideas', function (Blueprint $table): void {
            $table->string('trend', 32)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('ideas')->where('trend', 'short-trend')->update(['trend' => 'seasonal']);

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('alter table ideas drop constraint if exists ideas_trend_check');
            DB::statement(
                "alter table ideas add constraint ideas_trend_check check (trend is null or trend in ('evergreen', 'seasonal'))"
            );
        }
    }
};
