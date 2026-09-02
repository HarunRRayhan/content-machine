<?php

namespace App\Support\Telegram;

final class TelegramMessageChunker
{
    public const MAX_LENGTH = 4096;

    /**
     * @return list<string>
     */
    public static function split(string $text): array
    {
        if ($text === '') {
            return [''];
        }

        $chunks = [];
        $length = mb_strlen($text);

        for ($offset = 0; $offset < $length; $offset += self::MAX_LENGTH) {
            $chunks[] = mb_substr($text, $offset, self::MAX_LENGTH);
        }

        return $chunks;
    }
}
