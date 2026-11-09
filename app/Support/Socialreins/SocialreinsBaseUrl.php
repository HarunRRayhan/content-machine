<?php

namespace App\Support\Socialreins;

use App\Support\LinkResolution\PublicUrlGuard;
use InvalidArgumentException;

/**
 * The SocialReins API base URL carries the workspace's bearer tokens, so it must be a public
 * HTTPS URL (no userinfo, query or fragment) whose DNS answers are all public; the
 * addresses checked are pinned for the request that uses them.
 */
final class SocialreinsBaseUrl
{
    public function __construct(private readonly ?PublicUrlGuard $urlGuard = null) {}

    /** Syntax only, trailing slash dropped. */
    public static function normalize(string $url): string
    {
        $url = rtrim(trim($url), '/');

        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            $parts = false;
        }

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException(
                'The SocialReins base URL must be a public HTTPS URL without credentials, query parameters, or fragments.'
            );
        }

        return $url;
    }

    /**
     * @return array{url: string, options: array<string, mixed>}
     */
    public function resolve(string $configured): array
    {
        $url = self::normalize($configured);
        $options = ($this->urlGuard ?? app(PublicUrlGuard::class))->curlResolveOptions($url);

        if ($options === null) {
            throw new InvalidArgumentException('The SocialReins base URL must resolve only to public network addresses.');
        }

        return ['url' => $url, 'options' => $options];
    }
}
