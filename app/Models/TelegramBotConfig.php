<?php

namespace App\Models;

use App\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\TelegramBotConfigFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's Telegram bot connection: at most one row per workspace
 * (workspace_id is unique). Whether the bot is "enabled" is never stored,
 * it's derived from whether a token is present, see isConnected(); a
 * disconnect (ClearTelegramBotConfigAction) nulls the token but keeps
 * webhook_secret/webhook_slug so reconnecting doesn't change the
 * workspace's webhook URL.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string|null $bot_token
 * @property string|null $webhook_secret
 * @property string|null $webhook_slug
 * @property string|null $bot_username
 * @property CarbonImmutable|null $connected_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TelegramBotConfig extends Model
{
    /** @use HasFactory<TelegramBotConfigFactory> */
    use BelongsToWorkspace, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'bot_token',
        'webhook_secret',
        'webhook_slug',
        'bot_username',
        'connected_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bot_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->bot_token !== null;
    }
}
