<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `status_transitions` is append-only: it is this project's git-history
     * replacement for content status changes, so rows are never updated or
     * deleted, and there is deliberately no `updated_at` column.
     */
    public function up(): void
    {
        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('from')->nullable();
            $table->string('to');
            $table->text('reason')->nullable();
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
        Schema::dropIfExists('status_transitions');
    }
};
