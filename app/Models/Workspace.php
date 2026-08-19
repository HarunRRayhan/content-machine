<?php

namespace App\Models;

use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property string $default_locale
 * @property array<string, mixed> $settings
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = ['team_id', 'name', 'slug', 'timezone', 'default_locale', 'settings'];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * The team this workspace belongs to.
     *
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The workspace resolved for the current request by SetCurrentWorkspace,
     * or null if no workspace has been bound (e.g. outside the dashboard
     * route group, or a user with no team yet).
     */
    public static function current(): ?self
    {
        return app(CurrentWorkspace::class)->get();
    }
}
