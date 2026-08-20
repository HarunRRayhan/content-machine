<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A minimal draft shell only, same shape and same "snapshot, not a
     * live reference" copy-in as posts (see that migration). Scripts,
     * decks, and scheduling are a later phase's expand migration onto
     * this same table, not built here.
     */
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('idea_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('number');
            $table->string('human_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->enum('status', ['draft'])->default('draft');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
