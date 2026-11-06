<?php

namespace Tests\Support;

use App\Support\LinkResolution\PublicUrlGuard;
use Illuminate\Support\Facades\Http;

/** Makes every host resolve to a public address (or the one given) for the SocialReins base URL check. */
trait FakesPublicDns
{
    protected function resolveHostsTo(string $ip = '93.184.216.34'): void
    {
        $this->app->instance(PublicUrlGuard::class, new PublicUrlGuard(
            fn (string $host): array => [['ip' => $ip]],
        ));
    }

    /** Fake brain's API; anything not stubbed answers 404 and never reaches the network. */
    protected function fakeHttp(array $stubs): void
    {
        Http::fake($stubs + ['*' => Http::response(['error' => 'not_stubbed'], 404)]);
        Http::preventStrayRequests();
    }
}
