<?php

namespace App\Support\LinkResolution;

use Closure;

/**
 * Rejects URLs that could make a worker call loopback, link-local, private, or
 * otherwise reserved network addresses. DNS is checked for every address so a
 * hostname cannot hide a private A/AAAA record behind a public-looking name.
 */
final class PublicUrlGuard
{
    /**
     * yt-dlp is only needed for known media platforms. Ordinary public pages
     * still use the metadata rung, but arbitrary hosts never reach a process
     * that may follow media-provider redirects.
     *
     * @var list<string>
     */
    private const YT_DLP_HOSTS = [
        'youtube.com',
        'youtu.be',
        'youtube-nocookie.com',
        'vimeo.com',
        'tiktok.com',
        'instagram.com',
        'facebook.com',
        'fb.watch',
        'x.com',
        'twitter.com',
        'linkedin.com',
        'reddit.com',
        'redd.it',
        'twitch.tv',
    ];

    /**
     * @param  (Closure(string): array<int, array<string, mixed>>)|null  $dnsResolver
     */
    public function __construct(
        private readonly ?Closure $dnsResolver = null,
    ) {}

    public function isSafe(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return false;
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return false;
        }

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || ($parts['user'] ?? null) !== null
            || ($parts['pass'] ?? null) !== null
        ) {
            return false;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ! in_array($port, [80, 443], true)) {
            return false;
        }

        $host = strtolower(rtrim($parts['host'], '.'));

        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublicIp($host);
        }

        // Numeric hostnames can be interpreted as legacy integer IPv4 forms
        // by different network libraries. Treat them as unsafe instead.
        if (preg_match('/^\d+$/', $host) === 1
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            return false;
        }

        $records = $this->dnsRecords($host);
        if ($records === []) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (! is_string($ip) || ! $this->isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    public function isAllowedForYtDlp(string $url): bool
    {
        if (! $this->isSafe($url)) {
            return false;
        }

        try {
            $host = parse_url($url, PHP_URL_HOST);
        } catch (\ValueError) {
            return false;
        }

        if (! is_string($host)) {
            return false;
        }

        $host = strtolower(rtrim($host, '.'));

        foreach (self::YT_DLP_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function dnsRecords(string $host): array
    {
        if ($this->dnsResolver !== null) {
            /** @var mixed $records */
            $records = ($this->dnsResolver)($host);

            return is_array($records) ? $records : [];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return is_array($records) ? $records : [];
    }
}
