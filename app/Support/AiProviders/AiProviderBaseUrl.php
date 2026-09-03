<?php

namespace App\Support\AiProviders;

use App\Support\LinkResolution\PublicUrlGuard;
use InvalidArgumentException;

/**
 * Keeps user-configured AI endpoints on public HTTPS hosts and pins their DNS
 * addresses for the request that uses them.
 */
final class AiProviderBaseUrl
{
    public function __construct(
        private readonly ?PublicUrlGuard $urlGuard = null,
    ) {}

    /**
     * @return array{url: string, options: array<string, mixed>}
     */
    public function resolve(?string $configured, string $default): array
    {
        if ($configured === null || trim($configured) === '') {
            return ['url' => $default, 'options' => []];
        }

        $url = trim($configured);

        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            $parts = false;
        }

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || ($parts['user'] ?? null) !== null
            || ($parts['pass'] ?? null) !== null
            || ($parts['query'] ?? null) !== null
            || ($parts['fragment'] ?? null) !== null
        ) {
            throw new InvalidArgumentException(
                'The AI provider base URL must be a public HTTPS URL without credentials, query parameters, or fragments.',
            );
        }

        $options = $this->guard()->curlResolveOptions($url);

        if ($options === null) {
            throw new InvalidArgumentException(
                'The AI provider base URL must resolve only to public network addresses.',
            );
        }

        return ['url' => rtrim($url, '/'), 'options' => $options];
    }

    private function guard(): PublicUrlGuard
    {
        return $this->urlGuard ?? new PublicUrlGuard;
    }
}
