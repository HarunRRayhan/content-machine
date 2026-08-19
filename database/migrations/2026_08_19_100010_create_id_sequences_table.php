<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per workspace+kind, holding the next number ReserveContentIdAction
     * will hand out. Never read/written directly outside that Action: the row
     * is locked (`lockForUpdate()`) and incremented inside a single
     * transaction to make reservation atomic under concurrent callers.
     */
    public function up(): void
    {
        Schema::create('id_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->integer('next_value')->default(1);

            $table->unique(['workspace_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('id_sequences');
    }
};
