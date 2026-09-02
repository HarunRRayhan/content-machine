<?php

namespace App\Actions\Telegram;

use App\Jobs\GenerateTelegramPostJob;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use App\Models\Transcription;
use App\Models\Workspace;
use App\Support\Telegram\TelegramUpdateKey;
use Illuminate\Support\Facades\DB;

/**
 * Starts a post request from a command, a pending /post prompt, or a media
 * caption. Capture still lands in Scratch Pad first, preserving the original
 * source even when generation later fails.
 */
class StartTelegramPostAction
{
    public function __construct(
        private readonly CaptureTelegramMessageAction $captureTelegramMessageAction,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(
        TelegramBotConfig $config,
        TelegramBotLink $link,
        int $chatId,
        int $telegramUserId,
        array $update,
        ?string $instruction = null,
        ?TelegramPostRequest $pendingRequest = null,
    ): TelegramPostRequest {
        $message = $update['message'] ?? null;
        if (! is_array($message)
            || ! is_array($message['chat'] ?? null)
            || ($message['chat']['type'] ?? null) !== 'private'
        ) {
            throw new \RuntimeException('Telegram post commands are available in private chats only.');
        }

        if ($link->telegram_bot_config_id !== $config->id || $link->telegram_user_id !== $telegramUserId) {
            throw new \RuntimeException('This Telegram account is not linked to this bot.');
        }

        $instruction = $instruction !== null ? trim($instruction) : null;

        return DB::transaction(function () use (
            $config,
            $link,
            $chatId,
            $telegramUserId,
            $update,
            $instruction,
            $pendingRequest,
        ): TelegramPostRequest {
            // Serialize one Telegram bot's post prompts. This prevents two
            // concurrent bare /post commands from stranding prompts or
            // capturing a second message before the first request is linked.
            Workspace::query()
                ->whereKey($config->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedConfig = TelegramBotConfig::query()
                ->whereKey($config->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($link->telegram_bot_config_id !== $lockedConfig->id
                || $link->telegram_user_id !== $telegramUserId
            ) {
                throw new \RuntimeException('This Telegram account is not linked to this bot.');
            }

            $telegramUpdateKey = TelegramUpdateKey::from($lockedConfig, $update);
            if ($telegramUpdateKey !== null) {
                $existingRequest = TelegramPostRequest::query()
                    ->where('telegram_update_key', $telegramUpdateKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingRequest !== null) {
                    if ($existingRequest->workspace_id !== $lockedConfig->workspace_id
                        || $existingRequest->telegram_bot_config_id !== $lockedConfig->id
                        || $existingRequest->telegram_user_id !== $telegramUserId
                        || $existingRequest->telegram_chat_id !== $chatId
                    ) {
                        throw new \RuntimeException('This Telegram post request belongs to another conversation.');
                    }

                    return $existingRequest;
                }
            }

            $request = null;

            if ($pendingRequest !== null) {
                $request = TelegramPostRequest::query()
                    ->whereKey($pendingRequest->id)
                    ->lockForUpdate()
                    ->first();

                if ($request === null
                    || $request->state !== TelegramPostRequest::AWAITING_INPUT
                    || $request->workspace_id !== $lockedConfig->workspace_id
                    || $request->telegram_bot_config_id !== $lockedConfig->id
                    || $request->telegram_user_id !== $telegramUserId
                    || $request->telegram_chat_id !== $chatId
                    || ($request->webhook_generation !== null
                        && $request->webhook_generation !== $lockedConfig->webhook_generation)
                ) {
                    throw new \RuntimeException('This Telegram post request is no longer awaiting input.');
                }

                if ($request->webhook_generation === null && $lockedConfig->webhook_generation !== null) {
                    $request->forceFill([
                        'webhook_generation' => $lockedConfig->webhook_generation,
                    ])->save();
                }
            } else {
                TelegramPostRequest::query()
                    ->forTelegram($lockedConfig, $telegramUserId, $chatId)
                    ->where('state', TelegramPostRequest::AWAITING_INPUT)
                    ->update([
                        'state' => TelegramPostRequest::CANCELLED,
                        'cancelled_at' => now(),
                    ]);
            }

            $sourceUpdate = $instruction === null || $instruction === ''
                ? $this->withoutBarePostCommand($update)
                : $this->withInstruction($update, $instruction);

            // Enrichment is queued below, after the request points at the
            // capture. A link/transcription worker can therefore never finish
            // before it has a request to advance or fail.
            $entry = $this->captureTelegramMessageAction->handle(
                $lockedConfig,
                $sourceUpdate,
                false,
                false,
                $telegramUpdateKey,
            );

            // A bare /post prompt keeps the prompt update's key, while the
            // follow-up source has its own key. On a replay after the request
            // update committed, the pending lookup above no longer finds it;
            // the captured source is still the durable idempotency bridge.
            if ($request === null && $entry !== null) {
                $existingRequest = TelegramPostRequest::query()
                    ->where('source_scratchpad_entry_id', $entry->id)
                    ->lockForUpdate()
                    ->first();

                if ($existingRequest !== null) {
                    if ($existingRequest->workspace_id !== $lockedConfig->workspace_id
                        || $existingRequest->telegram_bot_config_id !== $lockedConfig->id
                        || $existingRequest->telegram_user_id !== $telegramUserId
                        || $existingRequest->telegram_chat_id !== $chatId
                    ) {
                        throw new \RuntimeException('This Telegram post request belongs to another conversation.');
                    }

                    return $existingRequest;
                }
            }

            $request ??= TelegramPostRequest::create([
                'workspace_id' => $lockedConfig->workspace_id,
                'telegram_bot_config_id' => $lockedConfig->id,
                'source_scratchpad_entry_id' => null,
                'telegram_user_id' => $telegramUserId,
                'telegram_chat_id' => $chatId,
                'telegram_update_key' => $telegramUpdateKey,
                'webhook_generation' => $lockedConfig->webhook_generation,
                'state' => TelegramPostRequest::AWAITING_INPUT,
                'instruction' => null,
            ]);

            if ($entry === null) {
                return $request;
            }

            $updated = TelegramPostRequest::query()
                ->whereKey($request->id)
                ->where('state', TelegramPostRequest::AWAITING_INPUT)
                ->update([
                    'source_scratchpad_entry_id' => $entry->id,
                    'state' => TelegramPostRequest::GENERATING,
                    'instruction' => $instruction !== null && $instruction !== '' ? $instruction : null,
                    'webhook_generation' => $lockedConfig->webhook_generation,
                    'error_message' => null,
                    'cancelled_at' => null,
                ]);

            if ($updated === 0) {
                return $request->fresh() ?? $request;
            }

            $request->refresh();
            $this->queueSourceWork($entry, $request);

            return $request;
        });
    }

    /**
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>
     */
    private function withInstruction(array $update, string $instruction): array
    {
        $message = $update['message'] ?? [];
        if (! is_array($message)) {
            return $update;
        }

        if (isset($message['photo'], $message['caption']) || isset($message['photo'])) {
            $message['caption'] = $instruction;
        } elseif (isset($message['voice'], $message['caption']) || isset($message['voice']) || isset($message['audio'])) {
            $message['caption'] = $instruction;
        } else {
            $message['text'] = $instruction;
        }

        $update['message'] = $message;

        return $update;
    }

    /**
     * Remove the command itself before passing a bare `/post` to the normal
     * capture action. Media messages keep their photo/audio payload while the
     * command caption is removed; a text-only command becomes an empty update
     * and therefore creates an awaiting-input request.
     *
     * @param  array<string, mixed>  $update
     * @return array<string, mixed>
     */
    private function withoutBarePostCommand(array $update): array
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return $update;
        }

        $commandText = $message['text'] ?? $message['caption'] ?? null;

        if (! is_string($commandText)
            || preg_match('/^\/post(?:@[A-Za-z0-9_]+)?$/i', trim($commandText)) !== 1
        ) {
            return $update;
        }

        if (isset($message['photo']) || isset($message['voice']) || isset($message['audio'])) {
            unset($message['caption']);
        } else {
            unset($message['text']);
        }

        $update['message'] = $message;

        return $update;
    }

    private function canGenerateNow(ScratchpadEntry $entry): bool
    {
        $entry = $entry->fresh() ?? $entry;

        if ($entry->kind === 'voice') {
            return $entry->transcriptions()->where('status', 'done')->exists();
        }

        if ($entry->kind === 'link') {
            return ($entry->meta['resolved_kind'] ?? null) !== null
                && ($entry->meta['resolved_kind'] ?? null) !== 'unresolved';
        }

        return true;
    }

    private function queueSourceWork(ScratchpadEntry $entry, TelegramPostRequest $request): void
    {
        $leaseId = (new ClaimTelegramPostWorkAction)->claim($request->id);

        if ($leaseId === null) {
            return;
        }

        if ($entry->kind === 'link') {
            ResolveScratchpadLinkJob::dispatch($entry, $request->id, $leaseId)->afterCommit();

            return;
        }

        if ($entry->kind === 'voice') {
            $transcription = $entry->transcriptions()->first();

            if (! $transcription instanceof Transcription) {
                throw new \RuntimeException('The audio transcription record is missing.');
            }

            TranscribeVoiceNoteJob::dispatch($transcription, $request->id, $leaseId)->afterCommit();

            return;
        }

        if ($this->canGenerateNow($entry)) {
            GenerateTelegramPostJob::dispatch($request->id, $leaseId)->afterCommit();
        } else {
            (new ClaimTelegramPostWorkAction)->release($request->id, $leaseId);
        }
    }
}
