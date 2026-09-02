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
 * @property string|null $telegram_update_key
 * @property string|null $webhook_generation
 * @property string $state
 * @property string|null $instruction
 * @property string|null $error_message
 * @property CarbonImmutable|null $work_claimed_at
 * @property string|null $work_lease_id
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
        'telegram_update_key',
        'webhook_generation',
        'state',
        'instruction',
        'error_message',
        'work_claimed_at',
        'work_lease_id',
        'confirmed_at',
        'cancelled_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $request): void {
            if ($request->webhook_generation !== null) {
                return;
            }

            $generation = TelegramBotConfig::query()
                ->whereKey($request->telegram_bot_config_id)
                ->value('webhook_generation');

            if (is_string($generation)) {
                $request->webhook_generation = $generation;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'work_claimed_at' => 'datetime',
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
        $query = $query
            ->where('telegram_bot_config_id', $config->id)
            ->where('telegram_user_id', $telegramUserId)
            ->where('telegram_chat_id', $chatId);

        if ($config->webhook_generation === null) {
            return $query->whereNull('webhook_generation');
        }

        // Requests created by an old web instance during the expand phase do
        // not have a generation yet. The contract migration handles any that
        // remain after the old fleet drains.
        return $query->where(function (Builder $generationQuery) use ($config): void {
            $generationQuery
                ->where('webhook_generation', $config->webhook_generation)
                ->orWhereNull('webhook_generation');
        });
    }
}
