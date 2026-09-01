<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TelegramPostRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The durable link between a Telegram conversation, its source capture, and
 * the generated Post. It is also the lookup key for later approve/publish
 * replies, so those replies do not depend on in-memory bot state.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $telegram_bot_config_id
 * @property int|null $post_id
 * @property int|null $source_scratchpad_entry_id
 * @property int $telegram_user_id
 * @property int $telegram_chat_id
 * @property string $state
 * @property string|null $instruction
 * @property string|null $error_message
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $cancelled_at
 */
class TelegramPostRequest extends Model
{
    /** @use HasFactory<TelegramPostRequestFactory> */
    use HasFactory;

    public const AWAITING_INPUT = 'awaiting_input';

    public const GENERATING = 'generating';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const APPROVED = 'approved';

    public const PUBLISHED = 'published';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    /**
     * @var list<string>
     */
    public const ACTIVE_STATES = [
        self::AWAITING_INPUT,
        self::GENERATING,
        self::AWAITING_APPROVAL,
        self::APPROVED,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'workspace_id',
        'telegram_bot_config_id',
        'post_id',
        'source_scratchpad_entry_id',
        'telegram_user_id',
        'telegram_chat_id',
        'state',
        'instruction',
        'error_message',
        'confirmed_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<TelegramBotConfig, $this>
     */
    public function telegramBotConfig(): BelongsTo
    {
        return $this->belongsTo(TelegramBotConfig::class);
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<ScratchpadEntry, $this>
     */
    public function sourceEntry(): BelongsTo
    {
        return $this->belongsTo(ScratchpadEntry::class, 'source_scratchpad_entry_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTelegram(Builder $query, TelegramBotConfig $config, int $telegramUserId, int $chatId): Builder
    {
        return $query
            ->where('telegram_bot_config_id', $config->id)
            ->where('telegram_user_id', $telegramUserId)
            ->where('telegram_chat_id', $chatId);
    }
}
