<?php

namespace Tests\Support\Telegram;

use App\Support\Telegram\TelegramApiResult;
use App\Support\Telegram\TelegramClientContract;
use App\Support\Telegram\TelegramFileDownloadResult;
use App\Support\Telegram\TelegramGetMeResult;

/**
 * A single reusable TelegramClientContract test double, replacing what
 * used to be four near-identical anonymous classes across the Telegram
 * test suite. Every method defaults to success (or, for downloadFile,
 * fails loudly — a test that exercises photo/voice capture must
 * explicitly configure willDownloadFile(), so a forgotten configuration
 * never silently passes). sendMessage/deleteWebhook calls are recorded so
 * tests can assert on what was actually sent, without each test writing
 * its own recording anonymous class.
 */
final class FakeTelegramClient implements TelegramClientContract
{
    /**
     * @var list<array{botToken: string, chatId: int, text: string}>
     */
    public array $sentMessages = [];

    /**
     * @var list<string>
     */
    public array $deleteWebhookCalledWith = [];

    /**
     * @var list<array{botToken: string, commands: array<int, array{command: string, description: string}>}>
     */
    public array $setMyCommandsCalledWith = [];

    private TelegramGetMeResult $getMeResult;

    private TelegramApiResult $setWebhookResult;

    private TelegramApiResult $deleteWebhookResult;

    private TelegramApiResult $sendMessageResult;

    private TelegramApiResult $setMyCommandsResult;

    private TelegramFileDownloadResult $downloadFileResult;

    public function __construct()
    {
        $this->getMeResult = TelegramGetMeResult::success('fake_bot');
        $this->setWebhookResult = TelegramApiResult::success();
        $this->deleteWebhookResult = TelegramApiResult::success();
        $this->sendMessageResult = TelegramApiResult::success();
        $this->setMyCommandsResult = TelegramApiResult::success();
        $this->downloadFileResult = TelegramFileDownloadResult::failure('FakeTelegramClient::willDownloadFile() was never configured for this test.');
    }

    public function willGetMe(TelegramGetMeResult $result): self
    {
        $this->getMeResult = $result;

        return $this;
    }

    public function willSetWebhook(TelegramApiResult $result): self
    {
        $this->setWebhookResult = $result;

        return $this;
    }

    public function willDownloadFile(TelegramFileDownloadResult $result): self
    {
        $this->downloadFileResult = $result;

        return $this;
    }

    public function getMe(string $botToken): TelegramGetMeResult
    {
        return $this->getMeResult;
    }

    public function setWebhook(string $botToken, string $url, string $secretToken): TelegramApiResult
    {
        return $this->setWebhookResult;
    }

    public function deleteWebhook(string $botToken): TelegramApiResult
    {
        $this->deleteWebhookCalledWith[] = $botToken;

        return $this->deleteWebhookResult;
    }

    public function sendMessage(string $botToken, int $chatId, string $text): TelegramApiResult
    {
        $this->sentMessages[] = ['botToken' => $botToken, 'chatId' => $chatId, 'text' => $text];

        return $this->sendMessageResult;
    }

    public function setMyCommands(string $botToken, array $commands): TelegramApiResult
    {
        $this->setMyCommandsCalledWith[] = ['botToken' => $botToken, 'commands' => $commands];

        return $this->setMyCommandsResult;
    }

    public function downloadFile(string $botToken, string $fileId): TelegramFileDownloadResult
    {
        return $this->downloadFileResult;
    }
}
