<?php

namespace App\Support\Telegram;

use Illuminate\Http\Client\Response;
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

    public function setWebhook(string $botToken, string $url, string $secretToken): TelegramApiResult
    {
        try {
            $response = Http::asForm()->timeout(10)->post(self::API_BASE_URL."/bot{$botToken}/setWebhook", [
                'url' => $url,
                'secret_token' => $secretToken,
                'allowed_updates' => json_encode(['message']),
            ]);
        } catch (Throwable) {
            return TelegramApiResult::failure('Could not reach Telegram to register the webhook.');
        }

        return $this->toApiResult($response, 'Telegram rejected the webhook registration.');
    }

    public function deleteWebhook(string $botToken): TelegramApiResult
    {
        try {
            $response = Http::timeout(10)->post(self::API_BASE_URL."/bot{$botToken}/deleteWebhook");
        } catch (Throwable) {
            return TelegramApiResult::failure('Could not reach Telegram to remove the webhook.');
        }

        return $this->toApiResult($response, 'Telegram rejected the webhook removal.');
    }

    public function sendMessage(string $botToken, int $chatId, string $text): TelegramApiResult
    {
        try {
            $response = Http::asForm()->timeout(10)->post(self::API_BASE_URL."/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (Throwable) {
            return TelegramApiResult::failure('Could not reach Telegram to send the reply.');
        }

        return $this->toApiResult($response, 'Telegram rejected the message.');
    }

    public function downloadFile(string $botToken, string $fileId): TelegramFileDownloadResult
    {
        try {
            $getFileResponse = Http::timeout(10)->get(self::API_BASE_URL."/bot{$botToken}/getFile", [
                'file_id' => $fileId,
            ]);
        } catch (Throwable) {
            return TelegramFileDownloadResult::failure('Could not reach Telegram to look up the file.');
        }

        if (! $getFileResponse->successful() || $getFileResponse->json('ok') !== true) {
            $description = $getFileResponse->json('description');

            return TelegramFileDownloadResult::failure(
                is_string($description) && $description !== '' ? $description : 'Telegram could not find that file.'
            );
        }

        $filePath = $getFileResponse->json('result.file_path');

        if (! is_string($filePath) || $filePath === '') {
            return TelegramFileDownloadResult::failure('Telegram did not return a path for that file.');
        }

        try {
            $contentResponse = Http::timeout(30)->get(self::API_BASE_URL."/file/bot{$botToken}/{$filePath}");
        } catch (Throwable) {
            return TelegramFileDownloadResult::failure('Could not reach Telegram to download the file.');
        }

        if (! $contentResponse->successful()) {
            return TelegramFileDownloadResult::failure('Telegram rejected the file download.');
        }

        return TelegramFileDownloadResult::success($contentResponse->body());
    }

    private function toApiResult(Response $response, string $genericError): TelegramApiResult
    {
        if ($response->successful() && $response->json('ok') === true) {
            return TelegramApiResult::success();
        }

        $description = $response->json('description');

        return TelegramApiResult::failure(
            is_string($description) && $description !== '' ? $description : $genericError
        );
    }
}
