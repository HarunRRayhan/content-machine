<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TelegramLinkCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short-lived, single-use code (GenerateTelegramLinkCodeAction) a team
 * member sends to the bot as `/link CODE` to prove which app user they
 * are (LinkTelegramAccountAction). expires_at/consumed_at are checked
 * together in isUsable(), not duplicated at each call site.
 *
 * @property int $id
 * @property int $telegram_bot_config_id
 * @property int $user_id
 * @property string $code
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TelegramLinkCode extends Model
{
    /** @use HasFactory<TelegramLinkCodeFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'telegram_bot_config_id',
        'user_id',
        'code',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<TelegramBotConfig, $this>
     */
    public function telegramBotConfig(): BelongsTo
    {
        return $this->belongsTo(TelegramBotConfig::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
