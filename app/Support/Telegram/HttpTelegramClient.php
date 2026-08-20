<?php

namespace App\Support\Telegram;

use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpTelegramClient implements TelegramClientContract
{
    private const API_BASE_URL = 'https://api.telegram.org';

    public function getMe(string $botToken): TelegramGetMeResult
    {
        try {
            $response = Http::timeout(10)->get(self::API_BASE_URL."/bot{$botToken}/getMe");
        } catch (Throwable) {
            return TelegramGetMeResult::failure('Could not reach Telegram. Check your network and try again.');
        }

        if ($response->status() === 401 || $response->status() === 404) {
            return TelegramGetMeResult::failure('Telegram rejected this token as invalid.');
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            $description = $response->json('description');

            return TelegramGetMeResult::failure(
                is_string($description) && $description !== ''
                    ? $description
                    : "Telegram returned an unexpected response (status {$response->status()})."
            );
        }

        $username = $response->json('result.username');

        if (! is_string($username) || $username === '') {
            return TelegramGetMeResult::failure('Telegram did not return a bot username for this token.');
        }

        return TelegramGetMeResult::success($username);
    }
}
