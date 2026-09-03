<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Existing posts predate the Telegram approval flow and are treated
            // as already approved so this migration does not block them.
            $table->string('approval_state')->default('approved')->index();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['approved_by_user_id']);
            $table->dropIndex(['approval_state']);
            $table->dropColumn(['approval_state', 'approved_at', 'approved_by_user_id']);
        });
    }
};
