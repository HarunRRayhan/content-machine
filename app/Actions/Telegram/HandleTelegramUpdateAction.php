<?php

namespace App\Actions\Telegram;

use App\Actions\Posts\ApprovePostAction;
use App\Actions\Postsyncer\EnqueuePostPublishAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Scratchpad\DeleteRecentScratchpadEntriesAction;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\TelegramPostRequest;
use App\Models\Video;
use App\Support\Telegram\TelegramClientContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Entry point for every Telegram update: resolves whether the sender is
 * linked to a workspace member (TelegramBotLink), routes slash commands,
 * and otherwise hands off to intent resolution, a chat reply, or plain
 * capture (text/link/photo/voice/audio). Commands work the same regardless of
 * AI provider or ai_chat_enabled state.
 *
 * Only a bare, non-URL text message is ever eligible for the AI-chat
 * branch (config->ai_chat_enabled and a working credential): a link, a
 * photo, a voice note, an audio file, or an AI failure always falls through to the same
 * capture path this bot always had, and /note always force-captures
 * regardless of ai_chat_enabled. Within that branch, ResolveTelegramIntentAction
 * gets first look: if the message clearly asks for one of the bot's
 * existing commands (/me, /videos, /posts, /notes, /clearnotes) in plain
 * language, it runs that command's own handling (intentReply() below) and
 * replies with exactly what typing the command would have produced.
 * Every one of those is a lookup except /clearnotes, which deletes; either
 * way it's the exact same effect typing the command would have had, no
 * paraphrasing, no model-chosen action. Only when that finds no intent
 * does GenerateTelegramChatReplyAction get the message as a normal chat
 * turn. There is still no general tool-calling/agent loop in this
 * codebase: intent resolution is one fixed classification into a fixed,
 * small set of commands the sender could already type by hand, nothing
 * the model chooses freely.
 *
 * Every message gets acknowledge()'d the instant it arrives, a heart
 * reaction on the message plus Telegram's typing indicator, before any of
 * the above runs: a fixed-response command replies fast enough that this
 * barely shows, but an AI-chat reply can take several seconds, and
 * without it the sender has zero feedback that anything is happening.
 */
class HandleTelegramUpdateAction
{
    private const HELP_TEXT = <<<'TEXT'
        Here's what I can do:

        /me: which account you're linked as
        /link CODE: link your Content Machine account
        /videos: your workspace's most recent videos
        /posts: your workspace's most recent posts
        /notes: your workspace's most recent Scratch Pad captures
        /note <text>: save a Scratch Pad note
        /post <text>: create a draft, or send the next photo/voice/audio
        /approve P-123: approve a generated draft
        /post-now P-123: publish an approved draft now
        /schedule P-123 YYYY-MM-DD HH:MM: schedule an approved draft
        /cancel: cancel the pending draft request
        /clearnotes: delete your workspace's most recent untriaged Scratch Pad notes
        /help: show this list
        TEXT;

    private const CAPTURE_DEFAULT_TEXT = "Forward me a link, a photo, a voice note, or an audio file, or just type, and I'll capture it to your Scratch Pad.";

    private const CHAT_DEFAULT_TEXT = "Forward me a link, a photo, a voice note, or an audio file and I'll capture it. Otherwise, just talk, I'll chat back, and things like \"show my notes\" or \"what videos do I have\" run that command for you. Use /note to capture text instead.";

    private const CHAT_FAILED_TEXT = "Couldn't generate a chat reply right now, so I saved this as a note instead.";

    private const LOVE_REACTION = '❤';

    public function __construct(
        private readonly CaptureTelegramMessageAction $captureTelegramMessageAction,
        private readonly CaptureTextNoteAction $captureTextNoteAction,
        private readonly LinkTelegramAccountAction $linkTelegramAccountAction,
        private readonly GenerateTelegramChatReplyAction $generateTelegramChatReplyAction,
        private readonly ResolveTelegramIntentAction $resolveTelegramIntentAction,
        private readonly DeleteRecentScratchpadEntriesAction $deleteRecentScratchpadEntriesAction,
        private readonly TelegramClientContract $client,
        private readonly ?StartTelegramPostAction $startTelegramPostAction = null,
        private readonly ?ApprovePostAction $approvePostAction = null,
        private readonly ?EnqueuePostPublishAction $enqueuePostPublishAction = null,
        private readonly ?CancelTelegramPostRequestAction $cancelTelegramPostRequestAction = null,
    ) {}

    /**
     * @param  array<string, mixed>  $update
     */
    public function handle(TelegramBotConfig $config, array $update): void
    {
        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $fromUserId = $message['from']['id'] ?? null;
        $fromUsername = $message['from']['username'] ?? null;
        $fromUsername = is_string($fromUsername) && $fromUsername !== '' ? $fromUsername : null;

        if (! is_int($chatId) || ! is_int($fromUserId)) {
            return;
        }

        $messageId = $message['message_id'] ?? null;
        $this->acknowledge($config, $chatId, is_int($messageId) ? $messageId : null);

        $text = $this->messageText($message);

        if ($text !== null && str_starts_with($text, '/')) {
            $this->handleCommand($config, $chatId, $fromUserId, $fromUsername, $text, $update);

            return;
        }

        $link = $this->findLink($config, $fromUserId);

        if ($link === null) {
            $this->reply($config, $chatId, $this->notLinkedMessage());

            return;
        }

        $pendingRequest = $this->pendingInputRequest($config, $fromUserId, $chatId);

        if ($pendingRequest !== null) {
            $request = $this->startPostAction()->handle(
                $config,
                $link,
                $chatId,
                $fromUserId,
                $update,
                null,
                $pendingRequest,
            );

            $this->reply($config, $chatId, $this->postStartReply($request));

            return;
        }

        if ($config->ai_chat_enabled && $text !== null && $this->isChatEligible($message, $text)) {
            $this->keepTyping($config, $chatId);
            $intent = $this->resolveTelegramIntentAction->handle($config->workspace, $text);

            if ($intent !== null) {
                $this->reply($config, $chatId, $this->intentReply($config, $link, $intent));

                return;
            }

            $this->keepTyping($config, $chatId);
            $chatReply = $this->generateTelegramChatReplyAction->handle($config->workspace, $link->user, $text);

            if ($chatReply !== null) {
                $this->reply($config, $chatId, $chatReply);

                return;
            }

            $this->reply($config, $chatId, self::CHAT_FAILED_TEXT);
        }

        $this->captureTelegramMessageAction->handle($config, $update);
    }

    /**
     * Fires the moment a message arrives, well before any reply text
     * exists: a heart reaction on the message itself (skipped if Telegram
     * didn't give a message_id) plus the typing indicator, both
     * best-effort and never allowed to block or fail message processing.
     */
    private function acknowledge(TelegramBotConfig $config, int $chatId, ?int $messageId): void
    {
        if ($config->bot_token === null) {
            return;
        }

        if ($messageId !== null) {
            $this->client->setMessageReaction($config->bot_token, $chatId, $messageId, self::LOVE_REACTION);
        }

        $this->keepTyping($config, $chatId);
    }

    /**
     * Telegram's typing indicator clears itself after a few seconds, so a
     * reply that takes longer (an AI completion call, especially one that
     * falls back across multiple credentials) needs this resent before
     * each blocking step to stay visible until reply() actually sends
     * something, which is what makes it stop.
     */
    private function keepTyping(TelegramBotConfig $config, int $chatId): void
    {
        if ($config->bot_token !== null) {
            $this->client->sendChatAction($config->bot_token, $chatId, 'typing');
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function isChatEligible(array $message, string $text): bool
    {
        if ($text === '' || isset($message['photo']) || isset($message['voice']) || isset($message['audio'])) {
            return false;
        }

        return filter_var($text, FILTER_VALIDATE_URL) === false;
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function handleCommand(TelegramBotConfig $config, int $chatId, int $fromUserId, ?string $fromUsername, string $text, array $update): void
    {
        [$command, $args] = $this->parseCommand($text);

        if ($command === '/start') {
            $link = $this->findLink($config, $fromUserId);
            $this->reply($config, $chatId, $link !== null
                ? "Welcome back, {$link->user->name}.\n\n".$this->helpText($config)
                : "Welcome. This bot isn't linked to your account yet.\n\n{$this->notLinkedMessage()}");

            return;
        }

        if ($command === '/link') {
            $this->handleLink($config, $chatId, $fromUserId, $fromUsername, $args);

            return;
        }

        if ($command === '/help') {
            $this->reply($config, $chatId, $this->helpText($config));

            return;
        }

        $link = $this->findLink($config, $fromUserId);

        if ($link === null) {
            $this->reply($config, $chatId, $this->notLinkedMessage());

            return;
        }

        match ($command) {
            '/me' => $this->reply($config, $chatId, $this->intentReply($config, $link, 'me')),
            '/videos' => $this->reply($config, $chatId, $this->intentReply($config, $link, 'videos')),
            '/posts' => $this->reply($config, $chatId, $this->intentReply($config, $link, 'posts')),
            '/notes' => $this->reply($config, $chatId, $this->intentReply($config, $link, 'notes')),
            '/note' => $this->handleNote($config, $chatId, $args),
            '/post' => $this->handlePost($config, $link, $chatId, $fromUserId, $args, $update),
            '/approve' => $this->handleApprove($config, $link, $chatId, $fromUserId, $args),
            '/post-now' => $this->handlePostNow($config, $link, $chatId, $fromUserId, $args),
            '/schedule' => $this->handleSchedule($config, $link, $chatId, $fromUserId, $args),
            '/cancel' => $this->handleCancel($config, $chatId, $fromUserId, $args),
            '/clearnotes' => $this->reply($config, $chatId, $this->intentReply($config, $link, 'clear_notes')),
            default => $this->reply($config, $chatId, 'Unknown command. Try /help.'),
        };
    }

    /**
     * Runs the exact same handling the matching slash command runs
     * (/me, /videos, /posts, /notes, /clearnotes), whether it was reached
     * by typing that command or by ResolveTelegramIntentAction recognizing
     * the same request in plain language. $intent is always one of
     * ResolveTelegramIntentAction::KNOWN_INTENTS or the literal command
     * names above, never model-chosen free text. Every branch but
     * clear_notes is a pure lookup; clear_notes deletes, same as typing
     * /clearnotes would.
     */
    private function intentReply(TelegramBotConfig $config, TelegramBotLink $link, string $intent): string
    {
        return match ($intent) {
            'me' => "You're linked as {$link->user->name} ({$link->user->email}).",
            'videos' => $this->recentVideos($config),
            'posts' => $this->recentPosts($config),
            'notes' => $this->recentNotes($config),
            'clear_notes' => $this->clearNotes($config),
            default => 'Unknown command. Try /help.',
        };
    }

    private function handleLink(TelegramBotConfig $config, int $chatId, int $fromUserId, ?string $fromUsername, string $args): void
    {
        $code = trim($args);

        if ($code === '') {
            $this->reply($config, $chatId, 'Send /link followed by the code shown in Settings → Telegram, e.g. /link AB12CD34.');

            return;
        }

        try {
            $link = $this->linkTelegramAccountAction->handle($config, $code, $fromUserId, $fromUsername);
        } catch (RuntimeException $e) {
            $this->reply($config, $chatId, $e->getMessage());

            return;
        }

        $this->reply($config, $chatId, "✅ Linked as {$link->user->name}. Send /help to see what I can do.");
    }

    private function handleNote(TelegramBotConfig $config, int $chatId, string $args): void
    {
        $body = trim($args);

        if ($body === '') {
            $this->reply($config, $chatId, 'Send /note followed by the text to capture, e.g. /note remember to renew the domain.');

            return;
        }

        $this->captureTextNoteAction->handle($config->workspace, null, CaptureTextNoteData::fromTelegram($body));
        $this->reply($config, $chatId, 'Captured.');
    }

    /**
     * @return array{0: string, 1: string} the lowercased command (with any
     *                                     "@BotUsername" suffix stripped) and the remaining text
     */
    private function parseCommand(string $text): array
    {
        [$command, $args] = array_pad(preg_split('/\s+/', trim($text), 2) ?: [], 2, '');
        $command = strtolower(explode('@', $command, 2)[0]);

        return [$command, trim($args)];
    }

    /**
     * Telegram puts captions on media messages rather than in `text`. A
     * caption beginning with a command is still an explicit command, while a
     * normal caption remains a plain capture.
     *
     * @param  array<string, mixed>  $message
     */
    private function messageText(array $message): ?string
    {
        $text = $message['text'] ?? $message['caption'] ?? null;

        return is_string($text) ? trim($text) : null;
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function handlePost(
        TelegramBotConfig $config,
        TelegramBotLink $link,
        int $chatId,
        int $telegramUserId,
        string $args,
        array $update,
    ): void {
        // An empty string deliberately clears `/post` from a media caption or
        // text message before capture. With no media/text left, Start creates
        // the durable awaiting-input request instead.
        $request = $this->startPostAction()->handle(
            $config,
            $link,
            $chatId,
            $telegramUserId,
            $update,
            $args,
        );

        $this->reply($config, $chatId, $this->postStartReply($request));
    }

    private function postStartReply(TelegramPostRequest $request): string
    {
        if ($request->state === TelegramPostRequest::AWAITING_INPUT) {
            return 'Send the text, photo, voice note, or audio file you want turned into a post. You can also use /cancel.';
        }

        if ($request->source_scratchpad_entry_id !== null) {
            return '✅ Got it. I\'m creating a draft and will send you a Content Machine preview when it\'s ready.';
        }

        return 'I could not capture that as a post source. Send text, a photo, a voice note, or an audio file.';
    }

    private function handleApprove(
        TelegramBotConfig $config,
        TelegramBotLink $link,
        int $chatId,
        int $telegramUserId,
        string $args,
    ): void {
        $post = $this->postTarget($config, $chatId, $telegramUserId, $args, [TelegramPostRequest::AWAITING_APPROVAL]);

        if ($post === null) {
            $this->reply($config, $chatId, 'No generated draft is waiting for approval. Use /approve P-123 or start one with /post.');

            return;
        }

        try {
            $approved = $this->approvePostAction()->handle($post, $link->user);
        } catch (RuntimeException $exception) {
            $this->reply($config, $chatId, $exception->getMessage());

            return;
        }

        $this->reply($config, $chatId, "✅ {$approved->human_id} approved. Send /post-now {$approved->human_id} or /schedule {$approved->human_id} YYYY-MM-DD HH:MM.");
    }

    private function handlePostNow(
        TelegramBotConfig $config,
        TelegramBotLink $link,
        int $chatId,
        int $telegramUserId,
        string $args,
    ): void {
        $post = $this->postTarget($config, $chatId, $telegramUserId, $args, [TelegramPostRequest::APPROVED]);

        if ($post === null) {
            $this->reply($config, $chatId, 'No approved draft found. Approve the preview first with /approve P-123.');

            return;
        }

        try {
            $this->enqueuePostPublishAction()->handle($post, $config->workspace, ['confirm_ask' => true]);
        } catch (ValidationException $exception) {
            $this->reply($config, $chatId, $this->validationMessage($exception));

            return;
        } catch (Throwable) {
            $this->reply($config, $chatId, 'I could not queue that post for publishing.');

            return;
        }

        $this->reply($config, $chatId, "🚀 {$post->human_id} is queued for immediate publishing.");
    }

    private function handleSchedule(
        TelegramBotConfig $config,
        TelegramBotLink $link,
        int $chatId,
        int $telegramUserId,
        string $args,
    ): void {
        [$identifier, $whenText] = $this->scheduleArguments($args);
        $post = $this->postTarget($config, $chatId, $telegramUserId, $identifier ?? '', [TelegramPostRequest::APPROVED]);

        if ($post === null) {
            $this->reply($config, $chatId, 'No approved draft found. Approve the preview first with /approve P-123.');

            return;
        }

        $when = $this->parseScheduleWhen($config, $whenText);

        if ($when === null) {
            $this->reply($config, $chatId, 'Use /schedule P-123 YYYY-MM-DD HH:MM, for example /schedule P-123 2026-09-03 09:00.');

            return;
        }

        try {
            $this->enqueuePostPublishAction()->handle($post, $config->workspace, [
                'when' => $when,
                'confirm_ask' => true,
            ]);
        } catch (ValidationException $exception) {
            $this->reply($config, $chatId, $this->validationMessage($exception));

            return;
        } catch (Throwable) {
            $this->reply($config, $chatId, 'I could not queue that post for scheduling.');

            return;
        }

        $this->reply($config, $chatId, "🗓️ {$post->human_id} is queued for {$when} ({$config->workspace->timezone}).");
    }

    private function handleCancel(TelegramBotConfig $config, int $chatId, int $telegramUserId, string $args): void
    {
        $request = TelegramPostRequest::query()
            ->forTelegram($config, $telegramUserId, $chatId)
            ->when(trim($args) !== '', function ($query) use ($config, $args) {
                $post = $this->findPost($config, trim(explode(' ', trim($args), 2)[0]));

                if ($post === null) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->where('post_id', $post->id);
            })
            ->whereIn('state', TelegramPostRequest::ACTIVE_STATES)
            ->latest('id')
            ->first();

        if ($request === null) {
            $this->reply($config, $chatId, 'There is no pending Telegram post request to cancel.');

            return;
        }

        $this->cancelPostRequestAction()->handle($request);
        $this->reply($config, $chatId, 'Cancelled the pending Telegram post request. The source capture and any draft remain in Content Machine.');
    }

    /**
     * @param  list<string>  $states
     */
    private function postTarget(TelegramBotConfig $config, int $chatId, int $telegramUserId, string $args, array $states): ?Post
    {
        $identifier = trim($args);

        if ($identifier !== '') {
            $identifier = trim(explode(' ', $identifier, 2)[0]);

            return $this->findPost($config, $identifier);
        }

        return TelegramPostRequest::query()
            ->forTelegram($config, $telegramUserId, $chatId)
            ->whereIn('state', $states)
            ->whereNotNull('post_id')
            ->with('post')
            ->latest('id')
            ->get()
            ->first(fn (TelegramPostRequest $request): bool => $request->post !== null)
            ?->post;
    }

    private function findPost(TelegramBotConfig $config, string $identifier): ?Post
    {
        $query = Post::query()->where('workspace_id', $config->workspace_id);

        if (ctype_digit($identifier)) {
            $query->where(function ($inner) use ($identifier) {
                $inner->where('human_id', 'P-'.$identifier)
                    ->orWhere('id', (int) $identifier);
            });
        } else {
            $query->whereRaw('LOWER(human_id) = ?', [strtolower($identifier)]);
        }

        return $query->first();
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function scheduleArguments(string $args): array
    {
        $args = trim($args);

        if ($args === '') {
            return [null, ''];
        }

        [$first, $rest] = array_pad(preg_split('/\s+/', $args, 2) ?: [], 2, '');

        if (preg_match('/^(?:[A-Za-z]+-\d+|\d+)$/', $first) === 1) {
            return [$first, trim($rest)];
        }

        return [null, $args];
    }

    private function parseScheduleWhen(TelegramBotConfig $config, string $whenText): ?string
    {
        if (trim($whenText) === '') {
            return null;
        }

        $timezone = $config->workspace->timezone ?: 'Asia/Dhaka';

        try {
            $when = CarbonImmutable::parse($whenText, $timezone);
        } catch (Throwable) {
            return null;
        }

        if ($when->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
            return null;
        }

        return $when->toIso8601String();
    }

    private function pendingInputRequest(TelegramBotConfig $config, int $telegramUserId, int $chatId): ?TelegramPostRequest
    {
        return TelegramPostRequest::query()
            ->forTelegram($config, $telegramUserId, $chatId)
            ->where('state', TelegramPostRequest::AWAITING_INPUT)
            ->latest('id')
            ->first();
    }

    private function validationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())->flatten()->first();

        return is_string($message) && $message !== '' ? $message : 'The request could not be completed.';
    }

    private function startPostAction(): StartTelegramPostAction
    {
        return $this->startTelegramPostAction ?? new StartTelegramPostAction($this->captureTelegramMessageAction);
    }

    private function approvePostAction(): ApprovePostAction
    {
        return $this->approvePostAction ?? app(ApprovePostAction::class);
    }

    private function enqueuePostPublishAction(): EnqueuePostPublishAction
    {
        return $this->enqueuePostPublishAction ?? app(EnqueuePostPublishAction::class);
    }

    private function cancelPostRequestAction(): CancelTelegramPostRequestAction
    {
        return $this->cancelTelegramPostRequestAction ?? app(CancelTelegramPostRequestAction::class);
    }

    private function findLink(TelegramBotConfig $config, int $telegramUserId): ?TelegramBotLink
    {
        return TelegramBotLink::query()
            ->where('telegram_bot_config_id', $config->id)
            ->where('telegram_user_id', $telegramUserId)
            ->with('user')
            ->first();
    }

    private function recentVideos(TelegramBotConfig $config): string
    {
        $videos = Video::query()->where('workspace_id', $config->workspace_id)->orderByDesc('created_at')->limit(10)->get();

        if ($videos->isEmpty()) {
            return 'No videos yet.';
        }

        return $videos->map(fn (Video $video) => "{$video->human_id} · {$video->title} · {$video->status}")->implode("\n");
    }

    private function recentPosts(TelegramBotConfig $config): string
    {
        $posts = Post::query()->where('workspace_id', $config->workspace_id)->orderByDesc('created_at')->limit(10)->get();

        if ($posts->isEmpty()) {
            return 'No posts yet.';
        }

        return $posts->map(fn (Post $post) => "{$post->human_id} · {$post->title} · {$post->status}")->implode("\n");
    }

    private function recentNotes(TelegramBotConfig $config): string
    {
        $entries = ScratchpadEntry::query()->where('workspace_id', $config->workspace_id)->orderByDesc('captured_at')->limit(10)->get();

        if ($entries->isEmpty()) {
            return 'No Scratch Pad captures yet.';
        }

        return $entries->map(function (ScratchpadEntry $entry) {
            $preview = $entry->title ?? $entry->body;
            $preview = $preview === null ? '(no preview)' : Str::limit($preview, 60);

            return "{$entry->kind}: {$preview} ({$entry->status})";
        })->implode("\n");
    }

    /**
     * Deletes the same set /notes just listed (the workspace's most recent
     * untriaged captures) via DeleteRecentScratchpadEntriesAction. Being
     * linked (TelegramBotLink, already checked before this runs) is the
     * only permission gate this bot has for any command; there's no
     * per-note ownership to check beyond that.
     */
    private function clearNotes(TelegramBotConfig $config): string
    {
        $deleted = $this->deleteRecentScratchpadEntriesAction->handle($config->workspace);

        return match (true) {
            $deleted === 0 => 'No notes to delete.',
            $deleted === 1 => 'Deleted 1 note.',
            default => "Deleted {$deleted} notes.",
        };
    }

    private function helpText(TelegramBotConfig $config): string
    {
        return self::HELP_TEXT."\n\n".($config->ai_chat_enabled ? self::CHAT_DEFAULT_TEXT : self::CAPTURE_DEFAULT_TEXT);
    }

    private function notLinkedMessage(): string
    {
        return "I don't recognize you yet. Get a link code from Settings → Telegram in the dashboard, then send /link CODE.";
    }

    private function reply(TelegramBotConfig $config, int $chatId, string $text): void
    {
        if ($config->bot_token !== null) {
            $this->client->sendMessage($config->bot_token, $chatId, $text);
        }
    }
}
