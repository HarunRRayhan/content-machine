<?php

namespace App\Support\LinkResolution;

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

    public function resolve(string $url): ResolvedLink
    {
        $viaYtDlp = $this->resolveViaYtDlp($url);

        if ($viaYtDlp !== null) {
            return $viaYtDlp;
        }

        return $this->resolveViaPageMetadata($url);
    }

    private function resolveViaYtDlp(string $url): ?ResolvedLink
    {
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
            title: $this->stringOrNull($decoded['title'] ?? null),
            description: $this->stringOrNull($decoded['description'] ?? null),
            thumbnailUrl: $this->stringOrNull($decoded['thumbnail'] ?? null),
        );
    }

    private function resolveViaPageMetadata(string $url): ResolvedLink
    {
        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->timeout(10)
                ->get($url);
        } catch (Throwable) {
            return ResolvedLink::unresolved('metadata only (page fetch failed)');
        }

        if (! $response->successful() || ! str_contains($response->header('Content-Type'), 'html')) {
            return ResolvedLink::unresolved('metadata only (page fetch failed)');
        }

        $html = $response->body();
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
            return $this->cleanText($matches[3]);
        }

        if (preg_match($contentFirst, $html, $matches) === 1) {
            return $this->cleanText($matches[2]);
        }

        return null;
    }

    private function matchTitleTag(string $html): ?string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches) !== 1) {
            return null;
        }

        return $this->cleanText($matches[1]);
    }

    private function cleanText(string $value): ?string
    {
        $decoded = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));

        return $decoded === '' ? null : $decoded;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
