<?php

namespace App\Support\LinkResolution;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * The deterministic resolution ladder for a forwarded URL: yt-dlp metadata
 * first (works for a video post on most platforms without login), then a
 * plain HTTP fetch of the page's Open Graph tags (works for an ordinary
 * article or a single-image post whose caption is the substance), and
 * finally an honest "couldn't read it" rather than a guess. No LLM and no
 * browser session runs here; a rung this app cannot yet climb (a
 * login-walled Instagram/Facebook page, a carousel's individual slide
 * images) is reported as unresolved, never invented.
 */
final class ProcessLinkResolver implements LinkResolverContract
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; content-machine/1.0; +https://cm.harun.dev)';

    private const MAX_REDIRECTS = 3;

    private const MAX_PAGE_BYTES = 1024 * 1024;

    private const MAX_TITLE_LENGTH = 255;

    private const MAX_DESCRIPTION_LENGTH = 20000;

    private const MAX_IMAGE_URL_LENGTH = 2048;

    public function __construct(
        private readonly ?PublicUrlGuard $urlGuard = null,
    ) {}

    public function resolve(string $url): ResolvedLink
    {
        if (! $this->guard()->isSafe($url)) {
            return ResolvedLink::unresolved('link must use a public http(s) URL');
        }

        $viaYtDlp = $this->resolveViaYtDlp($url);

        if ($viaYtDlp !== null) {
            return $viaYtDlp;
        }

        return $this->resolveViaPageMetadata($url);
    }

    private function resolveViaYtDlp(string $url): ?ResolvedLink
    {
        if (! $this->guard()->isAllowedForYtDlp($url)) {
            return null;
        }

        try {
            $result = Process::timeout(25)->run([
                'yt-dlp',
                '--dump-json',
                '--no-warnings',
                '--skip-download',
                '--socket-timeout', '15',
                $url,
            ]);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        // yt-dlp prints one JSON object per line (a playlist prints several);
        // a single forwarded URL is one entry, so only the first line is read.
        $firstLine = strtok($result->output(), "\n");
        $decoded = is_string($firstLine) ? json_decode($firstLine, true) : null;

        if (! is_array($decoded)) {
            return null;
        }

        return new ResolvedLink(
            kind: 'video',
            resolvedVia: 'yt-dlp metadata',
            title: $this->stringOrNull($decoded['title'] ?? null, self::MAX_TITLE_LENGTH),
            description: $this->stringOrNull($decoded['description'] ?? null, self::MAX_DESCRIPTION_LENGTH),
            thumbnailUrl: $this->stringOrNull($decoded['thumbnail'] ?? null, self::MAX_IMAGE_URL_LENGTH),
        );
    }

    private function resolveViaPageMetadata(string $url): ResolvedLink
    {
        $currentUrl = $url;

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            if (! $this->guard()->isSafe($currentUrl)) {
                return ResolvedLink::unresolved('metadata only (redirected to an unsafe URL)');
            }

            try {
                $response = Http::withUserAgent(self::USER_AGENT)
                    ->timeout(10)
                    ->withOptions([
                        'allow_redirects' => false,
                        'stream' => true,
                    ])
                    ->get($currentUrl);
            } catch (Throwable) {
                return ResolvedLink::unresolved('metadata only (page fetch failed)');
            }

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                /** @var mixed $location */
                $location = $response->header('Location');

                if (! is_string($location) || $location === '' || $redirect === self::MAX_REDIRECTS) {
                    return ResolvedLink::unresolved('metadata only (page fetch failed)');
                }

                $currentUrl = $this->redirectUrl($currentUrl, $location);

                if ($currentUrl === null) {
                    return ResolvedLink::unresolved('metadata only (redirected to an unsafe URL)');
                }

                continue;
            }

            break;
        }

        if (! $response->successful() || ! str_contains($response->header('Content-Type'), 'html')) {
            return ResolvedLink::unresolved('metadata only (page fetch failed)');
        }

        $html = $this->readBodyWithinLimit($response, self::MAX_PAGE_BYTES);
        if ($html === null) {
            return ResolvedLink::unresolved('metadata only (page too large)');
        }

        $title = $this->matchOpenGraphTag($html, 'og:title') ?? $this->matchTitleTag($html);
        $description = $this->matchOpenGraphTag($html, 'og:description');
        $image = $this->matchOpenGraphTag($html, 'og:image');

        if ($title === null && $description === null) {
            return ResolvedLink::unresolved('metadata only (no readable title or description)');
        }

        return new ResolvedLink(
            kind: 'webpage',
            resolvedVia: $this->matchOpenGraphTag($html, 'og:title') !== null
                ? 'page metadata (og tags)'
                : 'page metadata (title tag only)',
            title: $title,
            description: $description,
            thumbnailUrl: $image,
        );
    }

    private function redirectUrl(string $baseUrl, string $location): ?string
    {
        try {
            $resolved = (string) UriResolver::resolve(new Uri($baseUrl), new Uri($location));
        } catch (Throwable) {
            return null;
        }

        return $this->guard()->isSafe($resolved) ? $resolved : null;
    }

    private function guard(): PublicUrlGuard
    {
        return $this->urlGuard ?? new PublicUrlGuard;
    }

    /**
     * Matches a <meta property="og:x" content="..."> tag regardless of
     * attribute order, since pages write these both ways. Each alternative
     * captures its own quote character with a backreference (\1 / \3)
     * rather than excluding both quote characters from the content class,
     * so a straight apostrophe inside a double-quoted content value (e.g.
     * content="What it's about.") isn't mistaken for the closing quote.
     */
    private function matchOpenGraphTag(string $html, string $property): ?string
    {
        $quotedProperty = preg_quote($property, '/');

        $propertyFirst = sprintf(
            '/<meta[^>]+property=(["\'])%s\1[^>]+content=(["\'])(.*?)\2/is',
            $quotedProperty,
        );
        $contentFirst = sprintf(
            '/<meta[^>]+content=(["\'])(.*?)\1[^>]+property=(["\'])%s\3/is',
            $quotedProperty,
        );

        if (preg_match($propertyFirst, $html, $matches) === 1) {
            return $this->cleanText($matches[3], $this->metadataValueLimit($property));
        }

        if (preg_match($contentFirst, $html, $matches) === 1) {
            return $this->cleanText($matches[2], $this->metadataValueLimit($property));
        }

        return null;
    }

    private function matchTitleTag(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) !== 1) {
            return null;
        }

        return $this->cleanText($matches[1], self::MAX_TITLE_LENGTH);
    }

    private function cleanText(string $value, int $maxLength): ?string
    {
        $decoded = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));

        if (mb_strlen($decoded) > $maxLength) {
            $decoded = mb_substr($decoded, 0, $maxLength);
        }

        return $decoded === '' ? null : $decoded;
    }

    private function stringOrNull(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if (mb_strlen($trimmed) > $maxLength) {
            $trimmed = mb_substr($trimmed, 0, $maxLength);
        }

        return $trimmed === '' ? null : $trimmed;
    }

    private function metadataValueLimit(string $property): int
    {
        return match ($property) {
            'og:title' => self::MAX_TITLE_LENGTH,
            'og:image' => self::MAX_IMAGE_URL_LENGTH,
            default => self::MAX_DESCRIPTION_LENGTH,
        };
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
}
