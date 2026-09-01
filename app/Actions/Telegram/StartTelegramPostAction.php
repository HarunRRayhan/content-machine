<?php

namespace App\Actions\Telegram;

use App\Jobs\GenerateTelegramPostJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;

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
        if ($link->telegram_bot_config_id !== $config->id || $link->telegram_user_id !== $telegramUserId) {
            throw new \RuntimeException('This Telegram account is not linked to this bot.');
        }

        $instruction = $instruction !== null ? trim($instruction) : null;

        if ($pendingRequest !== null) {
            if ($pendingRequest->state !== TelegramPostRequest::AWAITING_INPUT
                || $pendingRequest->telegram_bot_config_id !== $config->id
                || $pendingRequest->telegram_user_id !== $telegramUserId
                || $pendingRequest->telegram_chat_id !== $chatId
            ) {
                throw new \RuntimeException('This Telegram post request is no longer awaiting input.');
            }
        } else {
            TelegramPostRequest::query()
                ->forTelegram($config, $telegramUserId, $chatId)
                ->where('state', TelegramPostRequest::AWAITING_INPUT)
                ->update([
                    'state' => TelegramPostRequest::CANCELLED,
                    'cancelled_at' => now(),
                ]);
        }

        $sourceUpdate = $instruction === null || $instruction === ''
            ? $this->withoutBarePostCommand($update)
            : $this->withInstruction($update, $instruction);
        $entry = $this->captureTelegramMessageAction->handle($config, $sourceUpdate, false);

        $request = $pendingRequest ?? TelegramPostRequest::create([
            'workspace_id' => $config->workspace_id,
            'telegram_bot_config_id' => $config->id,
            'source_scratchpad_entry_id' => null,
            'telegram_user_id' => $telegramUserId,
            'telegram_chat_id' => $chatId,
            'state' => TelegramPostRequest::AWAITING_INPUT,
            'instruction' => null,
        ]);

        if ($entry !== null) {
            $updated = TelegramPostRequest::query()
                ->whereKey($request->id)
                ->where('state', TelegramPostRequest::AWAITING_INPUT)
                ->update([
                    'source_scratchpad_entry_id' => $entry->id,
                    'state' => TelegramPostRequest::GENERATING,
                    'instruction' => $instruction !== null && $instruction !== '' ? $instruction : null,
                    'error_message' => null,
                    'cancelled_at' => null,
                ]);

            if ($updated === 0) {
                return $request->fresh() ?? $request;
            }

            $request->refresh();
        }

        if ($entry !== null && $this->canGenerateNow($entry)) {
            GenerateTelegramPostJob::dispatch($request->id);
        }

        return $request;
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
}
