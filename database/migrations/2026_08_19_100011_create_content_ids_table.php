<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per reserved human-readable id (see ReserveContentIdAction).
     * `entity_type`/`entity_id` stay null at reservation time, before the
     * entity that will carry the id exists; a later phase may point them at
     * it once created. There is deliberately no `updated_at`: a reservation
     * is a fact that happened at `reserved_at`, never edited afterwards.
     */
    public function up(): void
    {
        Schema::create('content_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->integer('number');
            $table->string('human_id');
            $table->foreignId('reserved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('reserved_via', ['web', 'api', 'cli']);
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestampTz('reserved_at');

            $table->unique(['workspace_id', 'kind', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_ids');
    }
};
