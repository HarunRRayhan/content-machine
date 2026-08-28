<?php

namespace Tests\Unit\Support\Content;

use App\Models\Post;
use App\Models\Workspace;
use App\Support\Content\PostWorkspaceBuckets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostWorkspaceBucketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bilingual_captions_yield_both_workspace_buckets_before_publish(): void
    {
        $workspace = Workspace::factory()->create();
        $captions = json_decode(
            file_get_contents(base_path('tests/Fixtures/postsyncer/p48_captions.json')),
            true,
        );

        $post = Post::factory()->for($workspace)->create([
            'language' => 'both',
            'platforms' => ['facebook', 'instagram', 'twitter', 'threads', 'bluesky', 'tiktok'],
            'captions' => $captions,
            'postsyncer' => null,
        ]);

        $buckets = (new PostWorkspaceBuckets)->forPost($post);

        $keys = array_column($buckets, 'key');

        $this->assertContains('bn', $keys);
        $this->assertContains('en', $keys);
        $this->assertContains('facebook', $buckets[array_search('bn', $keys, true)]['platforms']);
        $this->assertContains('twitter', $buckets[array_search('en', $keys, true)]['platforms']);
    }

    public function test_postsyncer_groups_take_precedence_over_captions(): void
    {
        $workspace = Workspace::factory()->create();

        $post = Post::factory()->for($workspace)->create([
            'captions' => [
                'Bangla' => ['facebook' => ['caption' => 'bn']],
                'English' => ['twitter' => ['caption' => 'en']],
            ],
            'postsyncer' => [
                'groups' => [
                    [
                        'post_id' => 1,
                        'platforms' => ['instagram'],
                        'lang' => 'english',
                    ],
                ],
            ],
        ]);

        $buckets = (new PostWorkspaceBuckets)->forPost($post);

        $this->assertSame([
            ['key' => 'en', 'groups' => [], 'platforms' => ['instagram']],
        ], $buckets);
    }
}
