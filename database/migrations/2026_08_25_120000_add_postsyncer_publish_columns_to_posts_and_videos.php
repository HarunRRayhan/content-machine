<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostSyncer publish orchestration columns for posts and videos.
 * Drive URLs, per-record PostSyncer group state, and publish job tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->jsonb('image_drive_urls')->nullable()->after('platforms');
            $table->jsonb('postsyncer')->nullable()->after('image_drive_urls');
            $table->string('publish_state', 32)->default('idle')->after('postsyncer');
            $table->text('publish_error')->nullable()->after('publish_state');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->string('video_drive_url')->nullable()->after('deck_manifest');
            $table->string('cover_drive_url')->nullable()->after('video_drive_url');
            $table->jsonb('postsyncer')->nullable()->after('cover_drive_url');
            $table->string('publish_state', 32)->default('idle')->after('postsyncer');
            $table->text('publish_error')->nullable()->after('publish_state');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn([
                'image_drive_urls',
                'postsyncer',
                'publish_state',
                'publish_error',
            ]);
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn([
                'video_drive_url',
                'cover_drive_url',
                'postsyncer',
                'publish_state',
                'publish_error',
            ]);
        });
    }
};
