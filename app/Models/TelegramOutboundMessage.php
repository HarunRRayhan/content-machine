<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TelegramOutboundMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Durable Telegram delivery record. Telegram has no idempotency key, so a
 * process dying after Telegram accepts a chunk can still cause one duplicate;
 * persisted chunk progress removes every wider retry window.
 *
 * @property int $id
 * @property int $telegram_bot_config_id
 * @property string|null $webhook_generation
 * @property int $chat_id
 * @property string $logical_key
 * @property list<string> $chunks
 * @property int $next_chunk
 * @property int $attempts
 * @property string $status
 * @property string|null $last_error
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $dispatch_claimed_at
 * @property string|null $dispatch_lease_id
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $discarded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TelegramOutboundMessage extends Model
{
    /** @use HasFactory<TelegramOutboundMessageFactory> */
    use HasFactory;

    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const UNCERTAIN = 'uncertain';

    public const DISCARDED = 'discarded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'telegram_bot_config_id',
        'webhook_generation',
        'chat_id',
        'logical_key',
        'chunks',
        'next_chunk',
        'attempts',
        'status',
        'last_error',
        'next_attempt_at',
        'dispatch_claimed_at',
        'dispatch_lease_id',
        'last_attempt_at',
        'sent_at',
        'failed_at',
        'discarded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunks' => 'array',
            'next_attempt_at' => 'datetime',
            'dispatch_claimed_at' => 'datetime',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'discarded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TelegramBotConfig, $this>
     */
    public function telegramBotConfig(): BelongsTo
    {
        return $this->belongsTo(TelegramBotConfig::class);
    }
}
