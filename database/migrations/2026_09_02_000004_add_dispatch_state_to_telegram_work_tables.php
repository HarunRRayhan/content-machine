<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->timestampTz('failed_at')->nullable()->after('processed_at');
            $table->timestampTz('discarded_at')->nullable()->after('failed_at');
            $table->text('last_error')->nullable()->after('discarded_at');
            $table->timestampTz('dispatch_claimed_at')->nullable()->after('last_error');
            $table->uuid('dispatch_lease_id')->nullable()->after('dispatch_claimed_at');
            $table->index(
                ['processed_at', 'failed_at', 'discarded_at', 'dispatch_claimed_at', 'id'],
                'telegram_updates_dispatch_scan_index',
            );
        });

        Schema::table('telegram_post_requests', function (Blueprint $table): void {
            $table->timestampTz('work_claimed_at')->nullable()->after('error_message');
            $table->uuid('work_lease_id')->nullable()->after('work_claimed_at');
            $table->index(['state', 'work_claimed_at', 'id'], 'telegram_post_requests_work_scan_index');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_post_requests', function (Blueprint $table): void {
            $table->dropIndex('telegram_post_requests_work_scan_index');
            $table->dropColumn(['work_claimed_at', 'work_lease_id']);
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropIndex('telegram_updates_dispatch_scan_index');
            $table->dropColumn([
                'failed_at',
                'discarded_at',
                'last_error',
                'dispatch_claimed_at',
                'dispatch_lease_id',
            ]);
        });
    }
};
