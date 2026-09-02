<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_outbound_messages', function (Blueprint $table): void {
            $table->timestampTz('dispatch_claimed_at')->nullable()->after('next_attempt_at');
            $table->uuid('dispatch_lease_id')->nullable()->after('dispatch_claimed_at');
            $table->index(['status', 'next_attempt_at', 'dispatch_claimed_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_outbound_messages', function (Blueprint $table): void {
            $table->dropIndex(['status', 'next_attempt_at', 'dispatch_claimed_at', 'id']);
            $table->dropColumn(['dispatch_claimed_at', 'dispatch_lease_id']);
        });
    }
};
