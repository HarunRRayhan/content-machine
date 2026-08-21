<?php

namespace App\Support\Telegram;

/**
 * The fixed command menu registered against every connected bot via
 * TelegramClientContract::setMyCommands(), shared by ConnectTelegramBotAction
 * (a fresh connect) and any data migration that needs to (re-)sync it for
 * bots that were already connected before the list last changed.
 */
final class TelegramBotCommands
{
    /**
     * @var array<int, array{command: string, description: string}>
     */
    public const LIST = [
        ['command' => 'start', 'description' => 'Get started'],
        ['command' => 'help', 'description' => 'See what I can do'],
        ['command' => 'me', 'description' => 'Which account you\'re linked as'],
        ['command' => 'link', 'description' => 'Link your account with a code'],
        ['command' => 'videos', 'description' => 'Your most recent videos'],
        ['command' => 'posts', 'description' => 'Your most recent posts'],
        ['command' => 'note', 'description' => 'Save a Scratch Pad note'],
    ];
}
