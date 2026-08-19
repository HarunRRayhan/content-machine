<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Schema-only in this phase, same as media_assets: links a media asset
     * to whatever it's attached to (a scratchpad entry today; a post/video
     * later) via a polymorphic `attachable`, with a `role` and `position`
     * for ordering a carousel or picking the cover among several.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['image', 'video', 'cover', 'audio', 'document', 'source']);
            $table->string('platform')->nullable();
            $table->smallInteger('position')->default(0);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
