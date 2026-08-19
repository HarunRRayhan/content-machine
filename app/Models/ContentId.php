<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ContentIdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single reserved human-readable id (e.g. "PI-7"), written by
 * ReserveContentIdAction. Append-only: a reservation is never edited after
 * it's made, so there's no `updated_at` column at all.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $kind
 * @property int $number
 * @property string $human_id
 * @property int|null $reserved_by_user_id
 * @property string $reserved_via
 * @property string|null $idempotency_key
 * @property string|null $entity_type
 * @property int|null $entity_id
 * @property CarbonImmutable $reserved_at
 */
class ContentId extends Model
{
    /** @use HasFactory<ContentIdFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'kind',
        'number',
        'human_id',
        'reserved_by_user_id',
        'reserved_via',
        'idempotency_key',
        'entity_type',
        'entity_id',
        'reserved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
        ];
    }

    /**
     * The workspace this id was reserved in.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The user who reserved it, if any (e.g. null for a system/CLI reservation).
     *
     * @return BelongsTo<User, $this>
     */
    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by_user_id');
    }
}
