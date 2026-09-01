<?php

namespace App\Support\Postsyncer;

use Exception;
use Illuminate\Http\Client\Response;
use Throwable;

class PostsyncerException extends Exception
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        public readonly bool $retryable = false,
        public readonly bool $outcomeUnknown = false,
        public readonly bool $responseReceived = false,
        public readonly bool $safeToRetry = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $message = $response->json('message') ?? $response->json('error') ?? $response->body();

        if (! is_string($message)) {
            $message = json_encode($message) ?: 'Unknown error';
        }

        $status = $response->status();
        $outcomeUnknown = in_array($status, [408, 425, 429], true) || $status >= 500;

        return new self(
            "PostSyncer API error {$status}: {$message}",
            $status,
            null,
            $outcomeUnknown,
            $outcomeUnknown,
            true,
        );
    }
}
