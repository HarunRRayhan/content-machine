<?php

namespace Tests\Unit\Actions\Socialreins;

use App\Actions\Socialreins\TakeDownPostsyncerGroupsAction;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TakeDownPostsyncerGroupsActionTest extends TestCase
{
    use RefreshDatabase;

    private function video(): Video
    {
        $workspace = Workspace::factory()->create(['settings' => []]);
        PostsyncerConfig::write($workspace, ['api_key' => 'k']);

        return Video::factory()->for($workspace->fresh())->create(['postsyncer' => ['groups' => [
            ['post_id' => '41', 'status' => 'SCHEDULED', 'platforms' => ['facebook'], 'language' => 'english'],
            ['post_id' => '42', 'status' => 'SCHEDULED', 'platforms' => ['tiktok'], 'language' => 'english'],
            ['post_id' => '43', 'status' => 'SCHEDULED', 'platforms' => ['youtube'], 'language' => 'english'],
        ]]]);
    }

    public function test_it_deletes_scheduled_groups_counts_a_missing_one_as_gone_and_reports_a_published_one(): void
    {
        Http::fake([
            'postsyncer.com/api/v1/posts/41' => fn (Request $r) => Http::response(['id' => 41, 'status' => 'SCHEDULED']),
            'postsyncer.com/api/v1/posts/42' => Http::response(['message' => 'Not found'], 404),
            'postsyncer.com/api/v1/posts/43' => Http::response(['id' => 43, 'status' => 'PUBLISHED']),
        ]);
        $video = $this->video();

        $problems = app(TakeDownPostsyncerGroupsAction::class)->handle($video);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('PostSyncer youtube: PUBLISHED', $problems[0]);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/posts/41'));
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/posts/43'));
        $this->assertSame(['DELETED', 'DELETED', 'SCHEDULED'], array_column($video->fresh()->postsyncer['groups'], 'status'));
    }
}
