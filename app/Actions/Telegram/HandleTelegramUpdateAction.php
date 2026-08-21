<?php

namespace App\Actions\Telegram;

use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\Video;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Entry point for every Telegram update: resolves whether the sender is
 * linked to a workspace member (TelegramBotLink), routes slash commands,
 * and otherwise hands off to intent resolution, a chat reply, or plain
 * capture (text/link/photo/voice). Commands work the same regardless of
 * AI provider or ai_chat_enabled state.
 *
 * Only a bare, non-URL text message is ever eligible for the AI-chat
 * branch (config->ai_chat_enabled and a working credential): a link, a
 * photo, a voice note, or an AI failure always falls through to the same
 * capture path this bot always had, and /note always force-captures
 * regardless of ai_chat_enabled. Within that branch, ResolveTelegramIntentAction
 * gets first look: if the message clearly asks for one of the bot's
 * existing read-only commands (/me, /videos, /posts, /notes) in plain
 * language, it runs that command's own lookup (intentReply() below) and
 * replies with exactly what typing the command would have produced, no
 * paraphrasing of the data. Only when that finds no intent does
 * GenerateTelegramChatReplyAction get the message as a normal chat turn.
 * There is still no general tool-calling/agent loop in this codebase:
 * intent resolution is one fixed classification into a fixed, small set
 * of commands the sender could already type by hand, nothing the model
 * chooses freely.
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
        /help: show this list
        TEXT;

    private const CAPTURE_DEFAULT_TEXT = "Forward me a link, a photo, or a voice note, or just type, and I'll capture it to your Scratch Pad.";

    private const CHAT_DEFAULT_TEXT = "Forward me a link, a photo, or a voice note and I'll capture it. Otherwise, just talk, I'll chat back, and things like \"show my notes\" or \"what videos do I have\" run that command for you. Use /note to capture text instead.";

    private const CHAT_FAILED_TEXT = "Couldn't generate a chat reply right now, so I saved this as a note instead.";

    private const LOVE_REACTION = '❤';

    public function __construct(
        private readonly CaptureTelegramMessageAction $captureTelegramMessageAction,
        private readonly CaptureTextNoteAction $captureTextNoteAction,
        private readonly LinkTelegramAccountAction $linkTelegramAccountAction,
        private readonly GenerateTelegramChatReplyAction $generateTelegramChatReplyAction,
        private readonly ResolveTelegramIntentAction $resolveTelegramIntentAction,
        private readonly TelegramClientContract $client,
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

        $text = $message['text'] ?? null;
        $text = is_string($text) ? trim($text) : null;

        if ($text !== null && str_starts_with($text, '/')) {
            $this->handleCommand($config, $chatId, $fromUserId, $fromUsername, $text);

            return;
        }

        $link = $this->findLink($config, $fromUserId);

        if ($link === null) {
            $this->reply($config, $chatId, $this->notLinkedMessage());

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
        if ($text === '' || isset($message['photo']) || isset($message['voice'])) {
            return false;
        }

        return filter_var($text, FILTER_VALIDATE_URL) === false;
    }

    private function handleCommand(TelegramBotConfig $config, int $chatId, int $fromUserId, ?string $fromUsername, string $text): void
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
            default => $this->reply($config, $chatId, 'Unknown command. Try /help.'),
        };
    }

    /**
     * Runs the exact same lookup the matching slash command runs
     * (/me, /videos, /posts, /notes), whether it was reached by typing
     * that command or by ResolveTelegramIntentAction recognizing the
     * same request in plain language. $intent is always one of
     * ResolveTelegramIntentAction::KNOWN_INTENTS or the literal command
     * names above, never model-chosen free text.
     */
    private function intentReply(TelegramBotConfig $config, TelegramBotLink $link, string $intent): string
    {
        return match ($intent) {
            'me' => "You're linked as {$link->user->name} ({$link->user->email}).",
            'videos' => $this->recentVideos($config),
            'posts' => $this->recentPosts($config),
            'notes' => $this->recentNotes($config),
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
        [$command, $args] = array_pad(explode(' ', $text, 2), 2, '');
        $command = strtolower(explode('@', $command, 2)[0]);

        return [$command, trim($args)];
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
