<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `model` is no longer required at creation time: a credential is now
     * saved with just label/provider/base_url/api_key, then
     * CreateAiProviderCredentialAction immediately checks the provider's
     * own list-models endpoint and stores whatever it found in
     * `discovered_models` (normalized to [{id, label}, ...]) for the
     * dashboard to offer as a picker. `discovered_models` is cleared the
     * moment a model is actually set (SetAiProviderCredentialModelAction),
     * it only ever represents "still needs a model chosen."
     */
    public function up(): void
    {
        Schema::table('ai_provider_credentials', function (Blueprint $table) {
            $table->string('model')->nullable()->change();
            $table->jsonb('discovered_models')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_credentials', function (Blueprint $table) {
            $table->dropColumn('discovered_models');
            $table->string('model')->nullable(false)->change();
        });
    }
};
