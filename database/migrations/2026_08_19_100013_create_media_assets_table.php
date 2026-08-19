<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Schema-only in this phase: nothing populates this table yet (no file
     * upload exists), it's created now so photo/voice capture later doesn't
     * need a new migration. `checksum_sha256` is how a later phase dedupes a
     * re-uploaded file within a workspace; the partial unique index below
     * only applies once a checksum is actually known, since Postgres (and
     * SQLite, which this constraint is also written to work under) treats
     * every NULL as distinct so a plain unique index would never collide on
     * two null checksums anyway, but being explicit keeps the intent clear.
     */
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->enum('kind', ['image', 'video', 'audio', 'document']);
            $table->string('disk');
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('bytes');
            $table->string('checksum_sha256')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('original_filename')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
        });

        // Laravel's Blueprint has no fluent partial-index builder, so this is
        // raw SQL. The `WHERE` partial-index syntax is identical on Postgres
        // and SQLite (both have supported it for years), so this works under
        // either driver without a per-driver branch.
        DB::statement(
            'create unique index media_assets_workspace_id_checksum_sha256_unique '.
            'on media_assets (workspace_id, checksum_sha256) '.
            'where checksum_sha256 is not null'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
