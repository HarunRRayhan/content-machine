<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Build a Request with a user resolver, for unit-testing middleware
     * directly without going through the full HTTP kernel (which is the
     * only place a plain Request::create() instance would otherwise pick
     * one up).
     */
    protected function fakeAccessibleDriveLinks(): void
    {
        Http::fake([
            'drive.usercontent.google.com/*' => Http::response('bytes', 200, [
                'Content-Type' => 'video/mp4',
            ]),
        ]);
    }

    protected function fakePrivateDriveLinks(): void
    {
        Http::fake([
            'drive.usercontent.google.com/*' => Http::response(
                'You need access. Request access from the owner.',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);
    }

    protected function requestAs(?Authenticatable $user, string $uri = '/'): Request
    {
        return Request::create($uri)->setUserResolver(fn () => $user);
    }
}
