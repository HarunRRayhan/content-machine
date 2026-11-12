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
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param  bool  $create  The request was POST /posts, which PostSyncer cannot deduplicate.
     */
    public static function fromResponse(Response $response, bool $create = false): self
    {
        $message = $response->json('message') ?? $response->json('error') ?? $response->body();

        if (! is_string($message)) {
            $message = json_encode($message) ?: 'Unknown error';
        }

        $status = $response->status();
        $retryAfter = $response->header('Retry-After');

        // Only PostSyncer's own API throttle answers before the request reaches a handler, so
        // only its 429 means nothing was stored and the request is safe to send again. Any other
        // 429 (a social platform's limit relayed by a handler that already stored the post)
        // keeps an unknown outcome. Laravel's throttle also stamps X-RateLimit-Remaining: 0 on
        // the last request it lets through, so for a create only the message counts.
        $throttled = $status === 429 && (
            stripos($message, 'Too Many Attempts') !== false
            || (! $create && $response->header('X-RateLimit-Remaining') === '0')
        );

        if ($throttled) {
            // The documented header is X-RateLimit-Reset (a UNIX time); Retry-After wins when sent.
            $reset = $response->header('X-RateLimit-Reset');

            return new self(
                "PostSyncer API error 429: {$message} PostSyncer's rate limit rejected this request before"
                .' storing anything. Retry in a minute.',
                429,
                responseReceived: true,
                safeToRetry: true,
                retryAfter: match (true) {
                    ctype_digit($retryAfter) => (int) $retryAfter,
                    ctype_digit($reset) => max(0, (int) $reset - time()),
                    default => null,
                },
            );
        }

        $outcomeUnknown = in_array($status, [408, 425, 429], true) || $status >= 500;

        return new self(
            "PostSyncer API error {$status}: {$message}",
            $status,
            null,
            $outcomeUnknown,
            $outcomeUnknown,
            true,
            retryAfter: ctype_digit($retryAfter) ? (int) $retryAfter : null,
        );
    }
}
