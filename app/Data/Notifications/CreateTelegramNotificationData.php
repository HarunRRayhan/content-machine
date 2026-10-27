<?php

namespace App\Data\Notifications;

use App\Http\Requests\Api\V1\StoreTelegramNotificationRequest;

final readonly class CreateTelegramNotificationData
{
    public function __construct(
        public string $key,
        public string $text,
    ) {}

    public static function fromRequest(StoreTelegramNotificationRequest $request): self
    {
        return new self(
            key: $request->string('key')->toString(),
            text: $request->string('text')->toString(),
        );
    }
}
