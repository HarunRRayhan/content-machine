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
 * and otherwise hands off to either a chat reply or plain capture
 * (text/link/photo/voice). Commands work the same regardless of AI
 * provider or ai_chat_enabled state.
 *
 * Only a bare, non-URL text message is ever eligible for the AI-chat
 * branch (config->ai_chat_enabled and a working credential), and even
 * then only when GenerateTelegramChatReplyAction actually returns a
 * reply: a link, a photo, a voice note, or an AI failure always falls
 * through to the same capture path this bot always had, and /note
 * always force-captures regardless of ai_chat_enabled. This is the only
 * "capability" the AI has today: it can talk, nothing else. There is no
 * tool-calling machinery in this codebase at all, so that boundary isn't
 * something this router enforces, it's the only shape available; a real
 * tool/permission model is a distinct, later, separately-approved
 * increment, not built here.
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

    private const CHAT_DEFAULT_TEXT = "Forward me a link, a photo, or a voice note and I'll capture it. Otherwise, just talk — I'll chat back. Use /note to capture text instead.";

    public function __construct(
        private readonly CaptureTelegramMessageAction $captureTelegramMessageAction,
        private readonly CaptureTextNoteAction $captureTextNoteAction,
        private readonly LinkTelegramAccountAction $linkTelegramAccountAction,
        private readonly GenerateTelegramChatReplyAction $generateTelegramChatReplyAction,
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
            $chatReply = $this->generateTelegramChatReplyAction->handle($config->workspace, $link->user, $text);

            if ($chatReply !== null) {
                $this->reply($config, $chatId, $chatReply);

                return;
            }
        }

        $this->captureTelegramMessageAction->handle($config, $update);
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
            '/me' => $this->reply($config, $chatId, "You're linked as {$link->user->name} ({$link->user->email})."),
            '/videos' => $this->reply($config, $chatId, $this->recentVideos($config)),
            '/posts' => $this->reply($config, $chatId, $this->recentPosts($config)),
            '/notes' => $this->reply($config, $chatId, $this->recentNotes($config)),
            '/note' => $this->handleNote($config, $chatId, $args),
            default => $this->reply($config, $chatId, 'Unknown command. Try /help.'),
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
