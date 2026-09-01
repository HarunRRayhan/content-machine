<?php

namespace Tests\Unit\Support\LinkResolution;

use App\Support\LinkResolution\PublicUrlGuard;
use Tests\TestCase;

class PublicUrlGuardTest extends TestCase
{
    public function test_it_accepts_public_ips_and_rejects_private_ips(): void
    {
        $guard = new PublicUrlGuard;

        $this->assertTrue($guard->isSafe('https://1.1.1.1/path'));
        $this->assertFalse($guard->isSafe('http://127.0.0.1/path'));
        $this->assertFalse($guard->isSafe('http://169.254.169.254/latest/meta-data'));
    }

    public function test_every_dns_address_must_be_public(): void
    {
        $public = new PublicUrlGuard(
            fn (string $host): array => [['ip' => '1.1.1.1']],
        );
        $mixed = new PublicUrlGuard(
            fn (string $host): array => [['ip' => '1.1.1.1'], ['ip' => '10.0.0.1']],
        );

        $this->assertTrue($public->isSafe('https://example.test/path'));
        $this->assertFalse($mixed->isSafe('https://example.test/path'));
    }

    public function test_it_rejects_credentials_and_non_http_ports(): void
    {
        $guard = new PublicUrlGuard(
            fn (string $host): array => [['ip' => '1.1.1.1']],
        );

        $this->assertFalse($guard->isSafe('https://user:pass@example.test/path'));
        $this->assertFalse($guard->isSafe('https://example.test:8080/path'));
    }

    public function test_yt_dlp_is_limited_to_known_media_hosts(): void
    {
        $guard = new PublicUrlGuard(
            fn (string $host): array => [['ip' => '1.1.1.1']],
        );

        $this->assertTrue($guard->isAllowedForYtDlp('https://www.youtube.com/watch?v=1'));
        $this->assertFalse($guard->isAllowedForYtDlp('https://youtube.com.evil.test/watch?v=1'));
    }
}
