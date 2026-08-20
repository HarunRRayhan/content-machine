<?php

namespace App\Data\Telegram;

use App\Http\Requests\Telegram\StoreTelegramBotConfigRequest;

/**
 * Typed input for ConnectTelegramBotAction.
 */
final readonly class ConnectTelegramBotData
{
    public function __construct(
        public string $botToken,
    ) {}

    public static function fromRequest(StoreTelegramBotConfigRequest $request): self
    {
        return new self(
            botToken: $request->string('bot_token')->toString(),
        );
    }
}
