<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_bot_configs', function (Blueprint $table): void {
            $table->string('connection_operation')->nullable()->after('connected_at');
            $table->uuid('connection_operation_id')->nullable()->after('connection_operation');
            $table->text('connection_operation_token')->nullable()->after('connection_operation_id');
            $table->string('connection_operation_username')->nullable()->after('connection_operation_token');
            $table->text('connection_operation_secret')->nullable()->after('connection_operation_username');
            $table->string('connection_operation_slug')->nullable()->after('connection_operation_secret');
            $table->uuid('connection_operation_generation')->nullable()->after('connection_operation_slug');
            $table->text('connection_cleanup_token')->nullable()->after('connection_operation_generation');
            $table->text('connection_operation_error')->nullable()->after('connection_cleanup_token');
            $table->timestampTz('connection_operation_started_at')->nullable()->after('connection_operation_error');
            $table->index(['connection_operation', 'connection_operation_id'], 'telegram_connection_operation_index');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_bot_configs', function (Blueprint $table): void {
            $table->dropIndex('telegram_connection_operation_index');
            $table->dropColumn([
                'connection_operation',
                'connection_operation_id',
                'connection_operation_token',
                'connection_operation_username',
                'connection_operation_secret',
                'connection_operation_slug',
                'connection_operation_generation',
                'connection_cleanup_token',
                'connection_operation_error',
                'connection_operation_started_at',
            ]);
        });
    }
};
