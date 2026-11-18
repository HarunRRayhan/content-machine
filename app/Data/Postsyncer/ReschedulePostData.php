<?php

namespace App\Data\Postsyncer;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

final readonly class ReschedulePostData
{
    public function __construct(
        public CarbonImmutable $when,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromApiPayload(array $payload): self
    {
        $rawWhen = $payload['when'] ?? null;

        if (! is_string($rawWhen) || trim($rawWhen) === '') {
            throw new InvalidArgumentException('A schedule time is required.');
        }

        try {
            return new self(CarbonImmutable::parse($rawWhen)->setSecond(0));
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'The schedule time is not a valid datetime.',
                previous: $exception,
            );
        }
    }
}
