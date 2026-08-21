<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `model` moved to ai_provider_credential_models (see the previous
     * migration, which already backfilled every existing value there).
     * `discovered_models` stays: it's the pool of models a credential's
     * provider says are available to add, independent of which of them
     * are actually active fallback rungs.
     */
    public function up(): void
    {
        Schema::table('ai_provider_credentials', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_credentials', function (Blueprint $table) {
            $table->string('model')->nullable()->after('base_url');
        });
    }
};
