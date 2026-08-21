<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\AiProviderCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One API key in a workspace's AI providers list. `provider` is the request
 * format (anthropic|openai), not a specific vendor, see the migration's
 * docblock. `api_key` is encrypted at rest; it's never sent back to the
 * client once saved, see CreateAiProviderCredentialData/
 * UpdateAiProviderCredentialData.
 *
 * `priority` here orders the providers panel itself, not the AI fallback
 * chain: which specific (credential, model) pairs actually get tried, and
 * in what order, lives on the related AiProviderCredentialModel rows (see
 * models()) and is what AiProviderCredentialResolver actually consumes.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $label
 * @property string $provider
 * @property string|null $base_url
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
        'discovered_models',
        'api_key',
        'priority',
        'enabled',
        'verified_at',
    ];

    /**
     * @return HasMany<AiProviderCredentialModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiProviderCredentialModel::class, 'ai_provider_credential_id');
    }

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
