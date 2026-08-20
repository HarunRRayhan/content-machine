<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A workspace's AI credentials, one row per key, ordered by `priority`
     * (lowest tried first). Priority ordering is the whole fallback-chain
     * mechanism, there's no separate "is default" flag to keep in sync with
     * it: position 0 among the enabled rows is the default. `provider`
     * names the request format (Anthropic Messages API vs OpenAI-shaped
     * chat completions), not a specific vendor: `base_url` lets an
     * openai-shaped credential target any compatible endpoint (Groq,
     * OpenRouter, a local model server), not just api.openai.com.
     */
    public function up(): void
    {
        Schema::create('ai_provider_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->enum('provider', ['anthropic', 'openai']);
            $table->string('base_url')->nullable();
            $table->string('model');
            $table->text('api_key');
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestampTz('verified_at')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_provider_credentials');
    }
};
