<?php

namespace App\Support;

use App\Models\Workspace;

/**
 * Holds the workspace resolved for the current request.
 *
 * Bound as a singleton in the container and populated by the
 * SetCurrentWorkspace middleware. Nothing else in this phase writes to it;
 * a request outside the dashboard route group (or a user with no team yet)
 * simply never has a workspace set, and Workspace::current() returns null.
 */
class CurrentWorkspace
{
    private ?Workspace $workspace = null;

    public function set(?Workspace $workspace): void
    {
        $this->workspace = $workspace;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }
}
