<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SocialReins publishes that succeeded before the settle step kept publish_progress.state
 * in step still read 'failed' there. Set it to 'succeeded' on those rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['posts', 'videos'] as $table) {
            DB::table($table)
                ->where('publish_state', 'succeeded')
                ->whereNotNull('publish_progress')
                ->select(['id', 'publish_progress'])
                ->orderBy('id')
                ->each(function (object $row) use ($table): void {
                    $progress = json_decode((string) $row->publish_progress, true);

                    if (! is_array($progress) || ($progress['driver'] ?? null) !== 'socialreins'
                        || ($progress['state'] ?? null) !== 'failed') {
                        return;
                    }

                    $progress['state'] = 'succeeded';
                    DB::table($table)->where('id', $row->id)->update(['publish_progress' => json_encode($progress)]);
                });
        }
    }

    public function down(): void {}
};
