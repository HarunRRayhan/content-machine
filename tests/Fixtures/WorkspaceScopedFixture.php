<?php

namespace Tests\Fixtures;

use App\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Throwaway model for proving BelongsToWorkspace's global scope works.
 * Nothing in the app uses this; its table is created/dropped by the test
 * that exercises it (tests/Unit/Concerns/BelongsToWorkspaceTest.php).
 */
class WorkspaceScopedFixture extends Model
{
    use BelongsToWorkspace;

    protected $table = 'workspace_scoped_fixtures';

    protected $fillable = ['name', 'workspace_id'];
}
