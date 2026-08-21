<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiProviderCredentialModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One active fallback rung: a specific model on a specific credential,
 * tagged `default` or `vision`. See the creating migration's docblock for
 * how the two purpose chains relate, and AiProviderCredentialResolver for
 * where this is actually consumed.
 *
 * @property int $id
 * @property int $ai_provider_credential_id
 * @property string $model
 * @property string $purpose
 * @property int $priority
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read AiProviderCredential $credential
 */
class AiProviderCredentialModel extends Model
{
    /** @use HasFactory<AiProviderCredentialModelFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ai_provider_credential_id',
        'model',
        'purpose',
        'priority',
    ];

    /**
     * @return BelongsTo<AiProviderCredential, $this>
     */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(AiProviderCredential::class, 'ai_provider_credential_id');
    }
}
