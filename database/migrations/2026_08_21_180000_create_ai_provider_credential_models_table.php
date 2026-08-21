<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fallback chain moves from "one model per credential" to "any
     * number of models per credential, each its own independently ordered
     * fallback rung": a workspace can add several models from the same
     * OpenRouter key, say, as separate retry candidates, not just one.
     *
     * `purpose` splits the chain in two: `default` for plain text/chat,
     * `vision` for models that can read images. A default task tries
     * `default` first and falls back to `vision` (a vision-capable model
     * can still do plain text); a vision task only ever tries `vision`
     * (the reverse doesn't hold, a vision-less model can't do that job).
     * See AiProviderCredentialResolver.
     *
     * `priority` here is scoped to (workspace, purpose) through the
     * credential relation, entirely independent of
     * ai_provider_credentials.priority, which still orders the provider
     * list itself (the right-hand panel), not which models get tried.
     */
    public function up(): void
    {
        Schema::create('ai_provider_credential_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_credential_id')->constrained()->cascadeOnDelete();
            $table->string('model');
            $table->enum('purpose', ['default', 'vision'])->default('default');
            $table->unsignedInteger('priority')->default(0);
            $table->timestampsTz();
        });

        $this->backfillExistingModels();
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_credential_models');
    }

    /**
     * Every credential that already had a single `model` set becomes one
     * `default`-purpose row here, keeping the credentials' own relative
     * priority order as the new rows' priority (renumbered per workspace
     * so it starts clean at 0). Queried through the plain query builder,
     * not the AiProviderCredential Eloquent model: that model no longer
     * declares a `model` property (it moved here), so it can't describe
     * the shape this one-time read needs.
     */
    private function backfillExistingModels(): void
    {
        $credentials = DB::table('ai_provider_credentials')
            ->whereNotNull('model')
            ->orderBy('workspace_id')
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'model']);

        $nextPriority = [];

        foreach ($credentials as $credential) {
            $workspaceId = $credential->workspace_id;
            $priority = $nextPriority[$workspaceId] ?? 0;

            DB::table('ai_provider_credential_models')->insert([
                'ai_provider_credential_id' => $credential->id,
                'model' => $credential->model,
                'purpose' => 'default',
                'priority' => $priority,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $nextPriority[$workspaceId] = $priority + 1;
        }
    }
};
