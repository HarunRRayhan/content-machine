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
     * Schema + model only in this phase: no controller/route/page reads or
     * writes this table yet (promotion/triage UI is a separate slice). Kept
     * here now so that slice is a UI change, not another migration.
     */
    public function up(): void
    {
        Schema::create('ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['post', 'video', 'feature']);
            $table->integer('number');
            $table->string('human_id');
            $table->string('title');
            $table->string('slug');
            $table->smallInteger('score')->nullable();
            $table->enum('trend', ['evergreen', 'seasonal'])->nullable();
            $table->text('rationale')->nullable();
            $table->text('body')->nullable();
            $table->string('editorial_type')->nullable();
            $table->enum('status', ['open', 'promoted', 'dropped'])->default('open');
            $table->text('drop_reason')->nullable();
            $table->foreignId('scratchpad_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('after_idea_id')->nullable()->constrained('ideas')->nullOnDelete();
            // No FK yet: the `videos` table doesn't exist in this phase.
            $table->unsignedBigInteger('after_video_id')->nullable();
            $table->jsonb('details')->default('{}');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'kind', 'number']);
        });

        // Blueprint has no fluent cross-driver CHECK-constraint builder, and
        // unlike the media_assets partial index, SQLite's ALTER TABLE can't
        // add a table-level CHECK after creation (only Postgres can here),
        // so this is gated to the driver that actually supports it. SQLite
        // is what this project's own CI currently runs Pest against (see
        // phpunit.xml), so this constraint is enforced app-side too, not
        // relied on as the only guard.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'alter table ideas add constraint ideas_score_between_0_and_1000 '.
                'check (score is null or (score >= 0 and score <= 1000))'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ideas');
    }
};
