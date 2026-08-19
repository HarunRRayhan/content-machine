<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scratchpad_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->ulid('public_id')->unique();
            $table->enum('kind', ['text', 'voice', 'photo', 'link', 'file']);
            $table->timestampTz('captured_at');
            $table->enum('source', ['web', 'telegram', 'api', 'cli'])->default('web');
            $table->string('language')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->enum('status', ['new', 'triaged', 'dropped'])->default('new');
            $table->string('intent')->nullable();
            $table->timestampTz('triaged_at')->nullable();
            $table->foreignId('triaged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('drop_reason')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scratchpad_entries');
    }
};
