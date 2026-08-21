<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\AiProviderCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One API key in a workspace's AI fallback chain. `provider` is the request
 * format (anthropic|openai), not a specific vendor, see the migration's
 * docblock. `api_key` is encrypted at rest; it's never sent back to the
 * client once saved, see CreateAiProviderCredentialData/
 * UpdateAiProviderCredentialData.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $label
 * @property string $provider
 * @property string|null $base_url
 * @property string|null $model
 * @property array<int, array{id: string, label: string}>|null $discovered_models
 * @property string $api_key
 * @property int $priority
 * @property bool $enabled
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AiProviderCredential extends Model
{
    /** @use HasFactory<AiProviderCredentialFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'label',
        'provider',
        'base_url',
        'model',
        'discovered_models',
        'api_key',
        'priority',
        'enabled',
        'verified_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'discovered_models' => 'array',
            'enabled' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }
}
