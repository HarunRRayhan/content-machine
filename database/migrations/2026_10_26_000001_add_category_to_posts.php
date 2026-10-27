<?php

use App\Support\Posts\PostCategoryFromSchedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds posts.category (default | morning | evening_carousel) for calendar
 * colors, and backfills scheduled posts from their earliest PostSyncer time
 * in the workspace timezone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->string('category', 32)->default('default')->after('template');
        });

        $timezones = DB::table('workspaces')->pluck('timezone', 'id');

        DB::table('posts')
            ->whereNotNull('postsyncer')
            ->select(['id', 'workspace_id', 'postsyncer'])
            ->orderBy('id')
            ->each(function (object $post) use ($timezones): void {
                $timezone = trim((string) ($timezones[$post->workspace_id] ?? '')) ?: 'Asia/Dhaka';
                $postsyncer = json_decode((string) $post->postsyncer, true);
                $category = PostCategoryFromSchedule::guess(is_array($postsyncer) ? $postsyncer : null, $timezone);

                if ($category !== 'default') {
                    DB::table('posts')->where('id', $post->id)->update(['category' => $category]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('category');
        });
    }
};
