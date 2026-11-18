<?php

namespace Tests\Feature\Socialreins;

use App\Models\Post;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A (language, platform) pair no driver can publish does not pull that platform onto PostSyncer for the other languages. */
class SocialreinsUnpublishablePairTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(bool $banglaXOnPostsyncer): Workspace
    {
        $workspace = Workspace::factory()->create();
        PostsyncerConfig::write($workspace, [
            'api_key' => 'k',
            'publish_enabled' => true,
            'default_language' => 'english',
            'languages' => [
                'english' => ['workspace_id' => '853', 'platforms' => [
                    'facebook' => ['account_id' => 100, 'handle' => '@fb'],
                    'twitter' => ['account_id' => 101, 'handle' => '@x'],
                ]],
                'bangla' => ['workspace_id' => '854', 'platforms' => [
                    'facebook' => ['account_id' => 200, 'handle' => '@fb-bn'],
                    'twitter' => ['account_id' => 201, 'handle' => '@x-bn', 'enabled' => $banglaXOnPostsyncer],
                ]],
            ],
            'post_types' => ['platforms' => [
                'facebook' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
                'twitter' => ['text' => 'on', 'photo' => 'on', 'reel' => 'on'],
            ]],
        ]);
        SocialreinsConfig::write($workspace, [
            'publish_driver' => 'socialreins',
            'base_url' => 'https://brain.test/v1',
            'default_language' => 'english',
            'languages' => [
                'english' => ['set_id' => '11111111-1111-4111-8111-111111111111', 'token' => 'sr_en', 'platforms' => ['facebook', 'twitter']],
                'bangla' => ['set_id' => '22222222-2222-4222-8222-222222222222', 'token' => 'sr_bn', 'platforms' => ['facebook']],
            ],
        ]);

        return $workspace->fresh();
    }

    private function makePost(Workspace $workspace): Post
    {
        return Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['facebook', 'twitter'],
            'captions' => [
                'English' => ['facebook' => ['caption' => 'EN fb'], 'twitter' => ['caption' => 'EN x']],
                'Bangla' => ['facebook' => ['caption' => 'BN fb'], 'twitter' => ['caption' => 'BN x']],
            ],
            'image_drive_urls' => [],
            'status' => 'ready',
        ]);
    }

    public function test_a_bangla_x_caption_with_no_bangla_x_account_leaves_english_x_on_socialreins(): void
    {
        $workspace = $this->workspace(banglaXOnPostsyncer: false);
        $post = $this->makePost($workspace);

        $this->assertNull(SocialreinsConfig::splitOf($post, $workspace));
        $this->assertTrue(SocialreinsConfig::routesToSocialreins($post, $workspace));
    }

    public function test_a_bangla_x_account_on_postsyncer_still_splits_x_onto_postsyncer(): void
    {
        $workspace = $this->workspace(banglaXOnPostsyncer: true);

        $this->assertSame(
            ['socialreins' => ['facebook'], 'postsyncer' => ['twitter']],
            SocialreinsConfig::splitOf($this->makePost($workspace), $workspace),
        );
    }
}
