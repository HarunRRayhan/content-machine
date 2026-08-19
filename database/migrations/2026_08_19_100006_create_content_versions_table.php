<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `content_versions` is append-only for the same reason as
     * `status_transitions`: it is this project's git-history replacement for
     * field-level content edits, so rows are never updated or deleted, and
     * there is deliberately no `updated_at` column.
     */
    public function up(): void
    {
        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->morphs('versionable');
            $table->string('field');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->enum('actor_type', ['user', 'token', 'system']);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('token_name')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_versions');
    }
};
