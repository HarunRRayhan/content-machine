<?php

namespace App\Actions\Scratchpad;

use App\Actions\Telegram\ClaimTelegramPostWorkAction;
use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Jobs\GenerateTelegramPostJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Transcription;
use App\Support\AiProviders\AiProviderCredentialResolver;
use App\Support\AiProviders\AiTranscriptionClientContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;

/**
 * Transcribes a voice note's audio, trying the workspace's openai-shaped AI
 * credentials in priority order (audio transcription has no
 * anthropic-shaped equivalent, so anthropic credentials in the chain are
 * skipped, not treated as failures). Never throws: every outcome, success
 * or exhausted fallback chain, is written to the Transcription row itself
 * (transcriptions.status/error_message), the same honest-degrade shape
 * this app already uses for link resolution and Telegram capture.
 *
 * A successful transcription backfills scratchpad_entries.language when
 * it wasn't already set, and — only for a Telegram-sourced entry — sends
 * the transcript back as a second bot message, since the "captured" reply
 * already went out before this job could possibly have finished.
 */
class TranscribeVoiceNoteAction
{
    public function __construct(
        private readonly AiTranscriptionClientContract $client,
        private readonly AiProviderCredentialResolver $resolver,
    ) {}

    public function handle(
        Transcription $transcription,
        ?int $workRequestId = null,
        ?string $workLeaseId = null,
    ): void {
        $transcription->refresh();

        if (in_array($transcription->status, ['done', 'failed'], true)) {
            return;
        }

        if (! $this->ownsPostWork($workRequestId, $workLeaseId)) {
            return;
        }

        $transcription->update(['status' => 'processing']);

        $mediaAsset = $transcription->mediaAsset;
        $workspace = $mediaAsset->workspace;

        $credentials = $this->resolver->credentialChain($workspace)->where('provider', 'openai');

        if ($credentials->isEmpty()) {
            $this->failTranscription(
                $transcription,
                'no_provider_configured',
                'No OpenAI-shaped AI provider is configured for this workspace.',
                $transcription->scratchpadEntry,
                'The audio could not be transcribed because no OpenAI-shaped AI provider is configured.',
                $workRequestId,
                $workLeaseId,
            );

            return;
        }

        try {
            $audioContents = Storage::disk($mediaAsset->disk)->get($mediaAsset->path);
        } catch (UnableToReadFile) {
            $audioContents = null;
        }

        if ($audioContents === null) {
            $this->failTranscription(
                $transcription,
                'audio_missing',
                'The audio file could not be read from storage.',
                $transcription->scratchpadEntry,
                'The audio file could not be read, so I could not create the post draft.',
                $workRequestId,
                $workLeaseId,
            );

            return;
        }

        $filename = $mediaAsset->original_filename ?? 'voice-note.ogg';
        $lastError = 'No provider attempt was made.';

        foreach ($credentials as $credential) {
            if ($workRequestId !== null
                && $workLeaseId !== null
                && ! $this->renewPostWork($workRequestId, $workLeaseId)
            ) {
                return;
            }

            $result = $this->client->transcribe($credential, $audioContents, $filename, $mediaAsset->mime);

            if (! $result->successful) {
                $lastError = (string) $result->error;

                continue;
            }

            $cancelledRequest = false;

            DB::transaction(function () use ($transcription, $result, $workRequestId, $workLeaseId, &$cancelledRequest): void {
                if ($workRequestId !== null) {
                    $lockedRequest = TelegramPostRequest::query()
                        ->whereKey($workRequestId)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedRequest === null) {
                        return;
                    }

                    if ($lockedRequest->state === TelegramPostRequest::GENERATING) {
                        if (! $this->ownsPostWorkRecord($lockedRequest, $workLeaseId)) {
                            return;
                        }
                    } elseif ($lockedRequest->state === TelegramPostRequest::CANCELLED
                        && $workLeaseId !== null
                        && $lockedRequest->work_lease_id === $workLeaseId
                    ) {
                        $cancelledRequest = true;
                    } else {
                        return;
                    }
                }

                $lockedTranscription = Transcription::query()
                    ->with('scratchpadEntry')
                    ->whereKey($transcription->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedTranscription === null
                    || in_array($lockedTranscription->status, ['done', 'failed'], true)
                ) {
                    return;
                }

                $lockedTranscription->forceFill([
                    'status' => 'done',
                    'provider' => 'openai',
                    'model' => 'whisper-1',
                    'language' => $result->language,
                    'text' => $result->text,
                ])->save();

                $entry = $lockedTranscription->scratchpadEntry;

                if ($entry === null) {
                    return;
                }

                if ($entry->language === null && $result->language !== null) {
                    $entry->update(['language' => $result->language]);
                }

                if (! $cancelledRequest) {
                    $this->replyOnTelegram($lockedTranscription, $entry, (string) $result->text);
                }
                $this->queueTelegramPostRequests($entry, $workRequestId, $workLeaseId);
            });

            return;
        }

        $this->failTranscription(
            $transcription,
            'transcription_failed',
            $lastError,
            $transcription->scratchpadEntry,
            'I could not transcribe that audio, so I could not create the post draft.',
            $workRequestId,
            $workLeaseId,
        );
    }

    private function replyOnTelegram(Transcription $transcription, ScratchpadEntry $entry, string $text): void
    {
        if ($entry->source !== 'telegram') {
            return;
        }

        $chatId = $entry->meta['telegram_chat_id'] ?? null;

        if (! is_int($chatId)) {
            return;
        }

        $config = TelegramBotConfig::query()->where('workspace_id', $entry->workspace_id)->first();

        if ($config === null || ! $config->isConnected()) {
            return;
        }

        (new QueueTelegramMessageAction)->handle(
            $config,
            $chatId,
            "📝 Transcript: {$text}",
            'telegram:transcription:'.$transcription->id.':transcript',
            $entry->webhook_generation,
        );
    }

    private function queueTelegramPostRequests(
        ?ScratchpadEntry $entry,
        ?int $currentRequestId = null,
        ?string $currentLeaseId = null,
    ): void {
        if ($entry === null) {
            return;
        }

        $claimAction = new ClaimTelegramPostWorkAction;

        TelegramPostRequest::query()
            ->where('source_scratchpad_entry_id', $entry->id)
            ->where('state', TelegramPostRequest::GENERATING)
            ->get()
            ->each(function (TelegramPostRequest $request) use ($claimAction, $currentRequestId, $currentLeaseId): void {
                if ($request->id === $currentRequestId && $currentLeaseId !== null) {
                    $claimAction->release($request->id, $currentLeaseId);
                }

                $leaseId = $claimAction->claim($request->id);
                if ($leaseId === null) {
                    return;
                }

                GenerateTelegramPostJob::dispatch($request->id, $leaseId)->afterCommit();
            });
    }

    private function failTranscription(
        Transcription $transcription,
        string $errorCode,
        string $errorMessage,
        ?ScratchpadEntry $entry,
        string $telegramMessage,
        ?int $workRequestId = null,
        ?string $workLeaseId = null,
    ): void {
        $cancelledRequest = false;

        DB::transaction(function () use (
            $transcription,
            $errorCode,
            $errorMessage,
            $entry,
            $telegramMessage,
            $workRequestId,
            $workLeaseId,
            &$cancelledRequest,
        ): void {
            if ($workRequestId !== null) {
                $lockedRequest = TelegramPostRequest::query()
                    ->whereKey($workRequestId)
                    ->lockForUpdate()
                    ->first();

                if ($lockedRequest === null) {
                    return;
                }

                if ($lockedRequest->state === TelegramPostRequest::GENERATING) {
                    if (! $this->ownsPostWorkRecord($lockedRequest, $workLeaseId)) {
                        return;
                    }
                } elseif ($lockedRequest->state === TelegramPostRequest::CANCELLED
                    && $workLeaseId !== null
                    && $lockedRequest->work_lease_id === $workLeaseId
                ) {
                    $cancelledRequest = true;
                } else {
                    return;
                }
            }

            $lockedTranscription = Transcription::query()
                ->with('scratchpadEntry')
                ->whereKey($transcription->id)
                ->lockForUpdate()
                ->first();

            if ($lockedTranscription === null
                || in_array($lockedTranscription->status, ['done', 'failed'], true)
            ) {
                return;
            }

            $lockedTranscription->forceFill([
                'status' => 'failed',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ])->save();

            if (! $cancelledRequest) {
                $this->failTelegramPostRequests(
                    $lockedTranscription->scratchpadEntry ?? $entry,
                    $telegramMessage,
                    $workRequestId,
                    $workLeaseId,
                );
            }
        });
    }

    private function renewPostWork(int $requestId, string $workLeaseId): bool
    {
        return (new ClaimTelegramPostWorkAction)->renew($requestId, $workLeaseId);
    }

    private function failTelegramPostRequests(
        ?ScratchpadEntry $entry,
        string $message,
        ?int $workRequestId = null,
        ?string $workLeaseId = null,
    ): void {
        if ($entry === null) {
            return;
        }

        $requests = TelegramPostRequest::query()
            ->where('source_scratchpad_entry_id', $entry->id)
            ->where('state', TelegramPostRequest::GENERATING)
            ->with('telegramBotConfig')
            ->when($workRequestId !== null, fn ($query) => $query->whereKey($workRequestId))
            ->get();

        foreach ($requests as $request) {
            DB::transaction(function () use ($request, $message, $workLeaseId): void {
                $lockedRequest = TelegramPostRequest::query()
                    ->with('telegramBotConfig')
                    ->whereKey($request->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedRequest === null
                    || $lockedRequest->state !== TelegramPostRequest::GENERATING
                    || ! $this->ownsPostWorkRecord($lockedRequest, $workLeaseId)
                ) {
                    return;
                }

                $lockedRequest->forceFill([
                    'state' => TelegramPostRequest::FAILED,
                    'error_message' => $message,
                    'work_claimed_at' => null,
                    'work_lease_id' => null,
                ])->save();

                $config = $lockedRequest->telegramBotConfig;
                if ($config !== null && $config->bot_token !== null) {
                    (new QueueTelegramMessageAction)->handle(
                        $config,
                        $lockedRequest->telegram_chat_id,
                        "❌ {$message}",
                        'telegram:post-request:'.$lockedRequest->id.':transcription-failure',
                        $lockedRequest->webhook_generation,
                    );
                }
            });
        }
    }

    private function ownsPostWork(?int $requestId, ?string $leaseId): bool
    {
        if ($requestId === null) {
            return true;
        }

        $query = TelegramPostRequest::query()
            ->whereKey($requestId)
            ->where('state', TelegramPostRequest::GENERATING);

        if ($leaseId === null) {
            $query->whereNull('work_lease_id');
        } else {
            $query
                ->where('work_lease_id', $leaseId)
                ->whereNotNull('work_claimed_at')
                ->where('work_claimed_at', '>', now()->subSeconds(ClaimTelegramPostWorkAction::LEASE_SECONDS));
        }

        return $query->exists();
    }

    private function ownsPostWorkRecord(TelegramPostRequest $request, ?string $leaseId): bool
    {
        if ($leaseId === null) {
            return $request->work_lease_id === null;
        }

        return $request->work_lease_id === $leaseId
            && $request->work_claimed_at !== null
            && $request->work_claimed_at->isAfter(now()->subSeconds(ClaimTelegramPostWorkAction::LEASE_SECONDS));
    }
}
