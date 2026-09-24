<?php

namespace App\Actions\Pexels;

use App\Models\Workspace;
use App\Support\Pexels\PexelsConfig;

class SavePexelsSettingsAction
{
    public function handle(Workspace $workspace, string $apiKey): void
    {
        PexelsConfig::write($workspace, $apiKey);
    }
}
