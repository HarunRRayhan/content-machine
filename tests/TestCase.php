<?php

namespace Tests;

use App\Models\Workspace;
use App\Support\Publishing\PlanningConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // CI runs the suite without a frontend build.
        $this->withoutVite();
    }

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

    /**
     * A workspace ready to publish through SocialReins, both languages covering every platform.
     * The post-type matrix goes where the planner still reads it: settings.postsyncer.post_types.
     *
     * @param  array<string, mixed>  $postTypes
     */
    protected function configureSocialreins(Workspace $workspace, array $postTypes = []): void
    {
        if ($postTypes !== []) {
            $workspace->forceFill(['settings' => ['postsyncer' => ['post_types' => $postTypes]] + ($workspace->settings ?? [])])->save();
        }

        SocialreinsConfig::write($workspace, [
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
            'tiktok_privacy_level' => 'SELF_ONLY',
            'languages' => [
                'english' => ['set_id' => '11111111-1111-4111-8111-111111111111', 'token' => 'sr_set_live_english', 'platforms' => PlanningConfig::PLATFORMS],
                'bangla' => ['set_id' => '22222222-2222-4222-8222-222222222222', 'token' => 'sr_set_live_bangla', 'platforms' => PlanningConfig::PLATFORMS],
            ],
        ]);
    }
}
