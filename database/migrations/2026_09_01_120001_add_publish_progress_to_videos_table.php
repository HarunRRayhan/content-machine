<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->jsonb('publish_progress')->nullable()->after('publish_error');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('publish_progress');
        });
    }
};
