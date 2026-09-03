<?php

namespace App\Support\Telegram;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class TelegramBotIdentityLock
{
    public const SECONDS = 120;

    public static function forWorkspace(int $workspaceId): Lock
    {
        return Cache::lock('telegram:bot-identity:workspace:'.$workspaceId, self::SECONDS);
    }
}
