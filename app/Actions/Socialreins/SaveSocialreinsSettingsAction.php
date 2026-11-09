<?php

namespace App\Actions\Socialreins;

use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveSocialreinsSettingsAction
{
    /** @param  array<string, mixed>  $input */
    public function handle(Workspace $workspace, array $input): void
    {
        DB::transaction(function () use ($workspace, $input): void {
            $locked = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();

            $before = $this->routing($locked);
            SocialreinsConfig::write($locked, $input);

            // Moving a workspace between drivers mid-publish would strand the run. Throwing
            // here rolls the write back.
            if ($before !== $this->routing($locked) && $this->hasPublishInFlight($locked)) {
                throw ValidationException::withMessages([
                    'publish_driver' => __('Wait for the running publish to finish before changing the publish driver or its languages.'),
                ]);
            }
        });
    }

    /** @return array{0: bool, 1: list<string>} */
    private function routing(Workspace $workspace): array
    {
        return [SocialreinsConfig::usesSocialreins($workspace), SocialreinsConfig::fromWorkspace($workspace)->routedLanguages()];
    }

    private function hasPublishInFlight(Workspace $workspace): bool
    {
        foreach ([Post::class, Video::class] as $model) {
            if ($model::query()->where('workspace_id', $workspace->getKey())
                ->whereIn('publish_state', ['queued', 'running'])->exists()) {
                return true;
            }
        }

        return false;
    }
}
