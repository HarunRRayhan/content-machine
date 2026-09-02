<?php

namespace App\Actions\Scratchpad;

use App\Actions\Telegram\ClaimTelegramPostWorkAction;
use App\Actions\Telegram\QueueTelegramMessageAction;
use App\Jobs\GenerateTelegramPostJob;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramPostRequest;
use App\Models\Transcription;
use App\Models\Workspace;
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
                $context = $this->lockTelegramWorkContext($transcription, $workRequestId, $workLeaseId);
                if ($context === null) {
                    return;
                }

                $lockedRequest = $context['request'];
                $config = $context['config'];
                $cancelledRequest = $context['cancelled'];

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
                    $this->replyOnTelegram($lockedTranscription, $entry, (string) $result->text, $config);
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

    private function replyOnTelegram(
        Transcription $transcription,
        ScratchpadEntry $entry,
        string $text,
        ?TelegramBotConfig $lockedConfig = null,
    ): void {
        if ($entry->source !== 'telegram') {
            return;
        }

        $chatId = $entry->meta['telegram_chat_id'] ?? null;
        if (! is_int($chatId)) {
            return;
        }

        $config = $lockedConfig ?? TelegramBotConfig::query()->where('workspace_id', $entry->workspace_id)->first();

        if ($config === null
            || ! $config->isConnected()
            || ($entry->webhook_generation !== null
                && $config->webhook_generation !== $entry->webhook_generation)
        ) {
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
            $context = $this->lockTelegramWorkContext($transcription, $workRequestId, $workLeaseId);
            if ($context === null) {
                return;
            }

            $cancelledRequest = $context['cancelled'];

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

    /**
     * Lock Telegram's identity before touching a request or its transcription.
     * A request canceled by rotation may still finish source enrichment, but
     * it must never enqueue a new draft or send a stale-generation reply.
     *
     * @return array{config: TelegramBotConfig|null, request: TelegramPostRequest|null, cancelled: bool}|null
     */
    private function lockTelegramWorkContext(
        Transcription $transcription,
        ?int $workRequestId,
        ?string $workLeaseId,
    ): ?array {
        if ($workRequestId !== null) {
            $reference = TelegramPostRequest::query()
                ->whereKey($workRequestId)
                ->first(['telegram_bot_config_id']);

            if ($reference === null) {
                return null;
            }

            $configReference = TelegramBotConfig::query()
                ->whereKey($reference->telegram_bot_config_id)
                ->first(['workspace_id']);

            if ($configReference === null) {
                return null;
            }

            Workspace::query()
                ->whereKey($configReference->workspace_id)
                ->lockForUpdate()
                ->first();

            $config = TelegramBotConfig::query()
                ->whereKey($reference->telegram_bot_config_id)
                ->lockForUpdate()
                ->first();
            $request = TelegramPostRequest::query()
                ->whereKey($workRequestId)
                ->lockForUpdate()
                ->first();

            if ($config === null || $request === null) {
                return null;
            }

            if ($request->webhook_generation === null && $config->webhook_generation !== null) {
                $request->webhook_generation = $config->webhook_generation;
            }

            $cancelled = $request->state === TelegramPostRequest::CANCELLED
                && $workLeaseId !== null
                && $request->work_lease_id === $workLeaseId;

            if ($cancelled) {
                return ['config' => $config, 'request' => $request, 'cancelled' => true];
            }

            if ($request->state !== TelegramPostRequest::GENERATING
                || $workLeaseId === null
                || ! $config->isConnected()
                || $request->webhook_generation !== $config->webhook_generation
                || ! $this->ownsPostWorkRecord($request, $workLeaseId)
            ) {
                return null;
            }

            return ['config' => $config, 'request' => $request, 'cancelled' => false];
        }

        $entryId = $transcription->scratchpad_entry_id;
        if ($entryId === null) {
            return ['config' => null, 'request' => null, 'cancelled' => false];
        }

        $entryReference = ScratchpadEntry::query()
            ->whereKey($entryId)
            ->first(['workspace_id', 'source', 'telegram_update_key']);

        if ($entryReference === null || $entryReference->source !== 'telegram') {
            return ['config' => null, 'request' => null, 'cancelled' => false];
        }

        Workspace::query()
            ->whereKey($entryReference->workspace_id)
            ->lockForUpdate()
            ->first();

        $config = TelegramBotConfig::query()
            ->where('workspace_id', $entryReference->workspace_id)
            ->lockForUpdate()
            ->first();

        if ($config === null) {
            return ['config' => null, 'request' => null, 'cancelled' => true];
        }

        $entry = ScratchpadEntry::query()
            ->whereKey($entryId)
            ->lockForUpdate()
            ->first();

        if ($entry === null) {
            return null;
        }

        if ($entry->webhook_generation === null && $config->webhook_generation !== null) {
            $entry->webhook_generation = $config->webhook_generation;
            $entry->save();
        } elseif ($entry->telegram_update_key === null
            && $entry->webhook_generation !== $config->webhook_generation
        ) {
            // Before generation tracking, a Telegram source created without a
            // connected config received a local fallback UUID. It is safe to
            // rebind that legacy source only when it has no update key.
            $entry->webhook_generation = $config->webhook_generation;
            $entry->save();
        }

        return [
            'config' => $config,
            'request' => null,
            'cancelled' => ! $config->isConnected()
                || $entry->webhook_generation !== $config->webhook_generation,
        ];
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
