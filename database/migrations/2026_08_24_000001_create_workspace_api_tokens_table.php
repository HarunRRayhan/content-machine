<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Workspace API tokens authenticate external clients (personal-content,
     * MCP) against exactly one workspace's JSON API. Only the SHA-256 hash
     * of the plaintext is stored — the plaintext is shown once at mint time
     * and never recoverable. Revocation stamps a timestamp instead of
     * deleting, so history rows attributed to the token keep their meaning.
     */
    public function up(): void
    {
        Schema::create('workspace_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->jsonb('abilities');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['workspace_id', 'revoked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_api_tokens');
    }
};
