<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ideas.after_video_id was left as a bare unsignedBigInteger when the
     * ideas table was created, with a comment explaining the videos table
     * didn't exist yet. It exists now, so this closes that gap.
     */
    public function up(): void
    {
        Schema::table('ideas', function (Blueprint $table) {
            $table->foreign('after_video_id')->references('id')->on('videos')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ideas', function (Blueprint $table) {
            $table->dropForeign(['after_video_id']);
        });
    }
};
