<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TelegramBotLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One app user's personal link to a workspace's shared Telegram bot,
 * created by LinkTelegramAccountAction once they send a valid /link code.
 * This is the access-control boundary a message's `from.id` is checked
 * against, see HandleTelegramUpdateAction.
 *
 * @property int $id
 * @property int $telegram_bot_config_id
 * @property int $user_id
 * @property int $telegram_user_id
 * @property string|null $telegram_username
 * @property CarbonImmutable $linked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TelegramBotLink extends Model
{
    /** @use HasFactory<TelegramBotLinkFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'telegram_bot_config_id',
        'user_id',
        'telegram_user_id',
        'telegram_username',
        'linked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
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
