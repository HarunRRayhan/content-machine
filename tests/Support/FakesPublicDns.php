<?php

namespace Tests\Support;

use App\Support\LinkResolution\PublicUrlGuard;

/** Makes every host resolve to a public address (or the one given) for the SocialReins base URL check. */
trait FakesPublicDns
{
    protected function resolveHostsTo(string $ip = '93.184.216.34'): void
    {
        $this->app->instance(PublicUrlGuard::class, new PublicUrlGuard(
            fn (string $host): array => [['ip' => $ip]],
        ));
    }
}
