<?php

namespace App\Actions\Socialreins;

use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Support\Facades\DB;

class SaveSocialreinsSettingsAction
{
    /** @param  array<string, mixed>  $input */
    public function handle(Workspace $workspace, array $input): void
    {
        // Under the workspace lock the publish enqueue takes too.
        DB::transaction(function () use ($workspace, $input): void {
            SocialreinsConfig::write(Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail(), $input);
        });
    }
}
