<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Telegram update_id already seen for a given bot, purely as an
 * idempotency guard (see the migration's docblock). Nothing here is ever
 * read back for its own sake.
 *
 * @property int $id
 * @property int $telegram_bot_config_id
 * @property int $update_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TelegramUpdate extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'telegram_bot_config_id',
        'update_id',
    ];

    /**
     * @return BelongsTo<TelegramBotConfig, $this>
     */
    public function telegramBotConfig(): BelongsTo
    {
        return $this->belongsTo(TelegramBotConfig::class);
    }
}
