<?php

namespace App\Support\LinkResolution;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Rejects URLs that could make a worker call loopback, link-local, private, or
 * otherwise reserved network addresses. DNS is checked for every address so a
 * hostname cannot hide a private A/AAAA record behind a public-looking name.
 */
final class PublicUrlGuard
{
    /**
     * IpUtils covers the private, reserved, documentation, benchmark, and
     * tunneling ranges. These additional ranges are not in its list but must
     * not be contacted by an application URL fetcher either.
     *
     * @var list<string>
     */
    private const ADDITIONAL_NON_PUBLIC_SUBNETS = [
        '192.88.99.0/24', // Deprecated 6to4 relay anycast
        '224.0.0.0/4',    // IPv4 multicast
        'ff00::/8',       // IPv6 multicast
    ];

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
        $parts = $this->safeParts($url);

        if ($parts === null) {
            return false;
        }

        if ($parts['ip'] !== null) {
            return $this->isPublicIp($parts['ip']);
        }

        return $this->publicRecordIps($this->dnsRecords($parts['dns_host'])) !== null;
    }

    /**
     * Return cURL's DNS pinning options for a URL that passed the same public
     * address check. The hostname remains in the URL for HTTP Host/SNI, while
     * the connection is forced to the addresses checked here.
     *
     * @return array{curl: array<int, list<string>|int>}|array{}|null null means unsafe
     */
    public function curlResolveOptions(string $url): ?array
    {
        $parts = $this->safeParts($url);

        if ($parts === null) {
            return null;
        }

        if ($parts['ip'] !== null) {
            return $this->isPublicIp($parts['ip']) ? [] : null;
        }

        $ips = $this->publicRecordIps($this->dnsRecords($parts['dns_host']));

        if ($ips === null || ! defined('CURLOPT_RESOLVE')) {
            return null;
        }

        // Some production runtimes have IPv6 DNS resolution but no IPv6
        // egress. If both families are available, pin only the public IPv4
        // addresses and force cURL to use them; otherwise cURL can select an
        // unreachable AAAA record and fail without trying the A records.
        $ipv4 = array_values(array_filter(
            $ips,
            fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        ));
        $pinnedIps = $ipv4 === [] ? $ips : $ipv4;

        $resolve = array_map(
            fn (string $ip): string => $parts['host'].':'.$parts['port'].':'
                .(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$ip.']' : $ip),
            $pinnedIps,
        );

        // CURLOPT_RESOLVE contains only the selected family, so cURL cannot
        // fall back to an unreachable AAAA record after this point.
        return ['curl' => [CURLOPT_RESOLVE => $resolve]];
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
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            return false;
        }

        try {
            return ! IpUtils::checkIp($ip, [
                ...IpUtils::PRIVATE_SUBNETS,
                ...self::ADDITIONAL_NON_PUBLIC_SUBNETS,
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{host: string, dns_host: string, port: int, ip: string|null}|null
     */
    private function safeParts(string $url): ?array
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            return null;
        }

        try {
            $parts = parse_url($url);
        } catch (\ValueError) {
            return null;
        }

        if (! is_array($parts)
            || ! is_string($parts['host'] ?? null)
            || ($parts['user'] ?? null) !== null
            || ($parts['pass'] ?? null) !== null
        ) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            return null;
        }

        $host = strtolower($parts['host']);
        $dnsHost = rtrim($host, '.');

        if ($dnsHost === '' || strlen($dnsHost) > 253) {
            return null;
        }

        $ip = filter_var($dnsHost, FILTER_VALIDATE_IP);
        if ($ip !== false) {
            return ['host' => $host, 'dns_host' => $dnsHost, 'port' => $port, 'ip' => $ip];
        }

        // Numeric hostnames can be interpreted as legacy integer IPv4 forms
        // by different network libraries. Treat them as unsafe instead.
        if ($this->looksLikeNumericIp($dnsHost)
            || filter_var($dnsHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            return null;
        }

        return ['host' => $host, 'dns_host' => $dnsHost, 'port' => $port, 'ip' => null];
    }

    private function looksLikeNumericIp(string $host): bool
    {
        return preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}$/i', $host) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return list<string>|null
     */
    private function publicRecordIps(array $records): ?array
    {
        if ($records === []) {
            return null;
        }

        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (! is_string($ip) || ! $this->isPublicIp($ip)) {
                return null;
            }

            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
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
