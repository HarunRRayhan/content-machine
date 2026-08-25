<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expand the draft post/video shells into full content records so
 * personal-content can treat Content Machine as the source of truth
 * (scripts, captions, platforms, deck packages) over the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('language', 8)->nullable()->after('title');
            $table->string('slug')->nullable()->after('language');
            $table->longText('script_markdown')->nullable()->after('body');
            $table->jsonb('captions')->nullable()->after('script_markdown');
            $table->jsonb('deck_manifest')->nullable()->after('captions');
            // human_id is the API address (V-12 or imported BV-53). Number can
            // collide across prefixes during import, so uniqueness moves here.
            $table->dropUnique(['workspace_id', 'number']);
            $table->unique(['workspace_id', 'human_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->string('language', 8)->nullable()->after('title');
            $table->string('slug')->nullable()->after('language');
            $table->jsonb('captions')->nullable()->after('body');
            $table->jsonb('platforms')->nullable()->after('captions');
            $table->dropUnique(['workspace_id', 'number']);
            $table->unique(['workspace_id', 'human_id']);
        });

        // Status was enum('draft') only. Widen to a free string so the
        // personal-content pipeline statuses fit without a Postgres enum dance
        // every time a new stage lands.
        $this->widenStatusColumn('videos');
        $this->widenStatusColumn('posts');

        // Deck packages reuse attachments.role = document with
        // media_assets.meta.purpose = "deck". Widening the attachments
        // enum is a separate migration once every driver path is settled.
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'human_id']);
            $table->unique(['workspace_id', 'number']);
            $table->dropColumn(['language', 'slug', 'script_markdown', 'captions', 'deck_manifest']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'human_id']);
            $table->unique(['workspace_id', 'number']);
            $table->dropColumn(['language', 'slug', 'captions', 'platforms']);
        });
    }

    private function widenStatusColumn(string $table): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // Laravel's enum() on Postgres is a CHECK constraint, not a
            // native enum type. Drop it before widening to varchar.
            DB::statement("alter table {$table} drop constraint if exists {$table}_status_check");
            DB::statement("alter table {$table} alter column status drop default");
            DB::statement("alter table {$table} alter column status type varchar(32) using status::text");
            DB::statement("alter table {$table} alter column status set default 'draft'");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->string('status', 32)->default('draft')->change();
        });
    }
};
