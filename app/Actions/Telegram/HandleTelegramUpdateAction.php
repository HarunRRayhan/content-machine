<?php

namespace App\Actions\Telegram;

use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Models\Post;
use App\Models\TelegramBotConfig;
use App\Models\TelegramBotLink;
use App\Models\Video;
use App\Support\Telegram\TelegramClientContract;
use RuntimeException;

/**
 * Entry point for every Telegram update: resolves whether the sender is
 * linked to a workspace member (TelegramBotLink), routes slash commands,
 * and otherwise hands plain capture (text/link/photo/voice) off to
 * CaptureTelegramMessageAction. Commands work the same whether or not the
 * workspace has an AI provider configured; the AI-chat layer that
 * replaces the default-capture branch when enabled is a separate, later
 * addition, not part of this router's job.
 */
class HandleTelegramUpdateAction
{
    private const HELP_TEXT = <<<'TEXT'
        Here's what I can do:

        /me — which account you're linked as
        /link CODE — link your Content Machine account
        /videos — your workspace's most recent videos
        /posts — your workspace's most recent posts
        /note <text> — save a Scratch Pad note
        /help — show this list

        Forward me a link, a photo, or a voice note, or just type, and I'll capture it to your Scratch Pad.
        TEXT;

    public function __construct(
        private readonly CaptureTelegramMessageAction $captureTelegramMessageAction,
        private readonly CaptureTextNoteAction $captureTextNoteAction,
        private readonly LinkTelegramAccountAction $linkTelegramAccountAction,
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

        $this->captureTelegramMessageAction->handle($config, $update);
    }

    private function handleCommand(TelegramBotConfig $config, int $chatId, int $fromUserId, ?string $fromUsername, string $text): void
    {
        [$command, $args] = $this->parseCommand($text);

        if ($command === '/start') {
            $link = $this->findLink($config, $fromUserId);
            $this->reply($config, $chatId, $link !== null
                ? "Welcome back, {$link->user->name}."."\n\n".self::HELP_TEXT
                : "Welcome. This bot isn't linked to your account yet.\n\n{$this->notLinkedMessage()}");

            return;
        }

        if ($command === '/link') {
            $this->handleLink($config, $chatId, $fromUserId, $fromUsername, $args);

            return;
        }

        if ($command === '/help') {
            $this->reply($config, $chatId, self::HELP_TEXT);

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
