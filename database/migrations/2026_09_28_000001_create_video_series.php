<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->timestampsTz();
            $table->unique(['workspace_id', 'slug']);
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->foreignId('series_id')->nullable()->constrained('video_series')->nullOnDelete();
            $table->unsignedInteger('series_part')->nullable();
            $table->unique(['series_id', 'series_part']);
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropUnique(['series_id', 'series_part']);
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn('series_part');
        });

        Schema::dropIfExists('video_series');
    }
};
