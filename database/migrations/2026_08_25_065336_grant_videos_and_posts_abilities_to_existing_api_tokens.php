<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Retained as a no-op for migration history. Existing tokens must not gain
 * new privileges implicitly; callers opt into new abilities by minting a new
 * token or explicitly updating an existing token in the dashboard.
 */
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
