<?php

namespace App\Actions\Postsyncer;

use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;

class UpdatePostsyncerSettingsAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Workspace $workspace, array $input): void
    {
        PostsyncerConfig::write($workspace, $input);
    }
}
