<?php

namespace App\Support\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class HttpTelegramClient implements TelegramClientContract
{
    private const API_BASE_URL = 'https://api.telegram.org';

    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

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
        $username = is_string($username) ? ltrim(trim($username), '@') : null;

        if (! is_string($username) || preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $username) !== 1) {
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
        foreach (TelegramMessageChunker::split($text) as $chunk) {
            try {
                $response = Http::asForm()->timeout(10)->post(self::API_BASE_URL."/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $chunk,
                ]);
            } catch (Throwable) {
                $result = TelegramApiResult::failure('Could not reach Telegram to send the reply.');
                $this->logMessageFailure($chatId, $result->error);

                return $result;
            }

            $result = $this->toApiResult($response, 'Telegram rejected the message.');
            if (! $result->successful) {
                $this->logMessageFailure($chatId, $result->error);

                return $result;
            }
        }

        return TelegramApiResult::success();
    }

    public function setMyCommands(string $botToken, array $commands): TelegramApiResult
    {
        try {
            $response = Http::asForm()->timeout(10)->post(self::API_BASE_URL."/bot{$botToken}/setMyCommands", [
                'commands' => json_encode($commands),
            ]);
        } catch (Throwable) {
            return TelegramApiResult::failure('Could not reach Telegram to register bot commands.');
        }

        return $this->toApiResult($response, 'Telegram rejected the command list.');
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

        $fileSize = $getFileResponse->json('result.file_size');
        if ((is_int($fileSize) || (is_string($fileSize) && ctype_digit($fileSize)))
            && (int) $fileSize > self::MAX_FILE_BYTES
        ) {
            return TelegramFileDownloadResult::failure('Telegram file is too large to capture.');
        }

        try {
            $contentResponse = Http::timeout(30)
                ->withOptions(['stream' => true])
                ->get(self::API_BASE_URL."/file/bot{$botToken}/{$filePath}");
        } catch (Throwable) {
            return TelegramFileDownloadResult::failure('Could not reach Telegram to download the file.');
        }

        if (! $contentResponse->successful()) {
            return TelegramFileDownloadResult::failure('Telegram rejected the file download.');
        }

        $contents = $this->readBodyWithinLimit($contentResponse, self::MAX_FILE_BYTES);

        return $contents === null
            ? TelegramFileDownloadResult::failure('Telegram file is too large to capture.')
            : TelegramFileDownloadResult::success($contents);
    }

    public function sendChatAction(string $botToken, int $chatId, string $action): TelegramApiResult
    {
        try {
            $response = Http::asForm()->timeout(10)->post(self::API_BASE_URL."/bot{$botToken}/sendChatAction", [
                'chat_id' => $chatId,
                'action' => $action,
            ]);
        } catch (Throwable) {
            return TelegramApiResult::failure('Could not reach Telegram to send the chat action.');
        }

        return $this->toApiResult($response, 'Telegram rejected the chat action.');
    }

    public function setMessageReaction(string $botToken, int $chatId, int $messageId, string $emoji): TelegramApiResult
    {
        try {
            $response = Http::asForm()->timeout(10)->post(self::API_BASE_URL."/bot{$botToken}/setMessageReaction", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reaction' => json_encode([['type' => 'emoji', 'emoji' => $emoji]]),
            ]);
        } catch (Throwable) {
            return TelegramApiResult::failure('Could not reach Telegram to set the reaction.');
        }

        return $this->toApiResult($response, 'Telegram rejected the reaction.');
    }

    private function toApiResult(Response $response, string $genericError): TelegramApiResult
    {
        if ($response->successful() && $response->json('ok') === true) {
            return TelegramApiResult::success();
        }

        $description = $response->json('description');
        $retryAfter = $response->json('parameters.retry_after');
        $retryAfter = is_int($retryAfter) || (is_string($retryAfter) && ctype_digit($retryAfter))
            ? max(1, (int) $retryAfter)
            : null;

        return TelegramApiResult::failure(
            is_string($description) && $description !== '' ? $description : $genericError,
            $retryAfter,
            $response->status(),
        );
    }

    private function readBodyWithinLimit(Response $response, int $maxBytes): ?string
    {
        try {
            $stream = $response->toPsrResponse()->getBody();
            $size = $stream->getSize();

            if ($size !== null && $size > $maxBytes) {
                return null;
            }

            $contents = '';
            $length = 0;

            while (! $stream->eof()) {
                $chunk = $stream->read(min(8192, $maxBytes - $length + 1));

                if ($chunk === '') {
                    break;
                }

                $length += strlen($chunk);
                if ($length > $maxBytes) {
                    return null;
                }

                $contents .= $chunk;
            }

            return $contents;
        } catch (Throwable) {
            return null;
        }
    }

    private function logMessageFailure(int $chatId, ?string $error): void
    {
        Log::warning('Telegram message delivery failed.', [
            'chat_id' => $chatId,
            'error' => $error,
        ]);
    }
}
