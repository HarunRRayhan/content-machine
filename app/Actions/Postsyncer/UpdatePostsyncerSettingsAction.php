<?php

namespace App\Actions\Postsyncer;

use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\LegacyPublishProgress;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePostsyncerSettingsAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Workspace $workspace, array $input): void
    {
        DB::transaction(function () use ($workspace, $input): void {
            $lockedWorkspace = Workspace::query()
                ->whereKey($workspace->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->hasPublishProgress($lockedWorkspace)
                && ! $this->isDisablingPublishing($lockedWorkspace, $input)) {
                throw ValidationException::withMessages([
                    'postsyncer' => __('PostSyncer settings cannot change while a publish operation has external progress. Retry or reconcile it first.'),
                ]);
            }

            PostsyncerConfig::write($lockedWorkspace, $input);
            $this->repairLegacyAccountFailures($lockedWorkspace);
        });
    }

    private function hasPublishProgress(Workspace $workspace): bool
    {
        foreach ([Post::class, Video::class] as $model) {
            $records = $model::query()
                ->where('workspace_id', $workspace->getKey())
                ->get(['publish_state', 'publish_error', 'publish_progress']);

            foreach ($records as $record) {
                if (in_array($record->publish_state, ['queued', 'running'], true)) {
                    return true;
                }

                if (LegacyPublishProgress::isMissingAccountFailure(
                    $record->publish_error,
                    $record->publish_progress,
                ) && is_array($record->publish_progress)
                    && ($record->publish_progress['completed_groups'] ?? []) === []) {
                    continue;
                }

                if ($record->publish_state !== 'failed'
                    || ! is_array($record->publish_progress)) {
                    continue;
                }

                if (($record->publish_progress['current'] ?? null) !== null
                    || ($record->publish_progress['completed_groups'] ?? []) !== []) {
                    return true;
                }
            }
        }

        return false;
    }

    private function repairLegacyAccountFailures(Workspace $workspace): void
    {
        foreach ([Post::class, Video::class] as $model) {
            $records = $model::query()
                ->where('workspace_id', $workspace->getKey())
                ->get(['id', 'publish_state', 'publish_error', 'publish_progress']);

            foreach ($records as $record) {
                if ($record->publish_state !== 'failed'
                    || ! LegacyPublishProgress::isMissingAccountFailure(
                        $record->publish_error,
                        $record->publish_progress,
                    )
                    || ! is_array($record->publish_progress)
                    || ($record->publish_progress['completed_groups'] ?? []) !== []) {
                    continue;
                }

                $record->forceFill([
                    'publish_state' => 'failed',
                    'publish_progress' => LegacyPublishProgress::markRetryable(
                        $record->publish_progress,
                    ),
                ])->save();
            }
        }
    }

    /**
     * Turning the global switch off is the emergency stop for queued work. It
     * must remain available even while a worker is waiting to call PostSyncer.
     *
     * @param  array<string, mixed>  $input
     */
    private function isDisablingPublishing(Workspace $workspace, array $input): bool
    {
        return array_key_exists('publish_enabled', $input)
            && filter_var($input['publish_enabled'], FILTER_VALIDATE_BOOLEAN) === false
            && PostsyncerConfig::fromWorkspace($workspace)->publishEnabled();
    }
}
