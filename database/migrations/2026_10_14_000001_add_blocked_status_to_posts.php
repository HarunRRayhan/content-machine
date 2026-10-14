<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `blocked` post status (stuck on an external limit, not publishable
 * for now) with a nullable `blocked_reason`, and merges the `dropped` post
 * status into `archived`. Posts never recorded a drop reason, so the merge
 * keeps a status_transitions row per affected post. Rollback restores `dropped`
 * on posts that still carry that marker and are archived. A post re-archived by
 * hand after this migration also matches, so it reverts too. Rollback also
 * drops blocked_reason, so those reasons are lost; the status_transitions rows stay.
 */
return new class extends Migration
{
    private const MERGE_REASON = 'merged dropped post status into archived';

    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->text('blocked_reason')->nullable()->after('status');
        });

        $ids = DB::table('posts')->where('status', 'dropped')->pluck('id');

        if ($ids->isNotEmpty()) {
            $now = now();

            DB::table('status_transitions')->insert($ids->map(fn ($id): array => [
                'subject_type' => 'App\\Models\\Post',
                'subject_id' => $id,
                'from' => 'dropped',
                'to' => 'archived',
                'reason' => self::MERGE_REASON,
                'actor_type' => 'system',
                'actor_id' => null,
                'token_name' => null,
                'created_at' => $now,
            ])->all());

            DB::table('posts')->whereIn('id', $ids)->update(['status' => 'archived']);
        }
    }

    public function down(): void
    {
        $ids = DB::table('status_transitions')
            ->where('subject_type', 'App\\Models\\Post')
            ->where('reason', self::MERGE_REASON)
            ->pluck('subject_id');

        DB::table('posts')
            ->whereIn('id', $ids)
            ->where('status', 'archived')
            ->update(['status' => 'dropped']);

        // blocked predates nothing in the old code, so fold it back to draft.
        DB::table('posts')->where('status', 'blocked')->update(['status' => 'draft', 'blocked_reason' => null]);

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('blocked_reason');
        });
    }
};
