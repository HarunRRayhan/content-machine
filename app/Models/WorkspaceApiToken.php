<?php

namespace App\Models;

use App\Models\Concerns\HasHashedToken;
use Carbon\CarbonImmutable;
use Database\Factories\WorkspaceApiTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bearer token that authenticates an external client (personal-content,
 * an MCP server) to exactly one workspace's JSON API. The plaintext is
 * shown once at mint time; only hash(plaintext) is stored, so a leaked DB
 * dump can't be replayed against the API.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by_user_id
 * @property string $name
 * @property string $token_hash
 * @property array<int, string> $abilities
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class WorkspaceApiToken extends Model
{
    /** @use HasFactory<WorkspaceApiTokenFactory> */
    use HasFactory, HasHashedToken;

    /**
     * The abilities a token can hold. Scratchpad and ideas cover capture
     * and triage; videos and posts cover the matching JSON API surfaces.
     * The mint form is driven from this list so it cannot drift.
     */
    final public const ABILITIES = [
        'scratchpad:read',
        'scratchpad:write',
        'ideas:read',
        'ideas:write',
        'videos:read',
        'videos:write',
        'posts:read',
        'posts:write',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'created_by_user_id',
        'name',
        'token_hash',
        'abilities',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * The workspace this token grants access to. A token is one workspace:
     * there is no header-based workspace switching, by design.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The user who minted this token. History rows are attributed to them
     * (Auth::user() is set to this user by the token middleware).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }
}
