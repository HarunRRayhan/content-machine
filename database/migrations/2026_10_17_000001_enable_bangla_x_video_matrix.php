<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sets the stored Bangla X matrix so Bangla X video (reel) is on. Only
     * workspaces with a stored post_types matrix are touched; the Settings UI
     * cannot edit the matrix, so the stored value must carry the change.
     * Idempotent: rerunning writes the same object.
     */
    public function up(): void
    {
        $twitter = ['text' => 'on', 'photo' => 'on', 'carousel' => 'on', 'thread' => 'off', 'reel' => 'on'];

        DB::table('workspaces')
            ->select(['id', 'settings'])
            ->orderBy('id')
            ->each(function (object $workspace) use ($twitter): void {
                $settings = is_string($workspace->settings) ? json_decode($workspace->settings, true) : null;
                $postsyncer = is_array($settings) && is_array($settings['postsyncer'] ?? null)
                    ? $settings['postsyncer']
                    : null;

                if ($postsyncer === null || ! is_array($postsyncer['post_types'] ?? null) || $postsyncer['post_types'] === []) {
                    return;
                }

                $postsyncer['post_types']['overrides']['bangla']['twitter'] = $twitter;
                $settings['postsyncer'] = $postsyncer;

                DB::table('workspaces')
                    ->where('id', $workspace->id)
                    ->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR)]);
            });
    }

    /**
     * The previous Bangla X values cannot be recovered, so rollback leaves the
     * stored matrix as it is.
     */
    public function down(): void {}
};
