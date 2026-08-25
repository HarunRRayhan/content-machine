<?php

namespace Tests\Feature\Posts;

use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PublishPostControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actingAsWorkspaceMember(): array
    {
        $workspace = Workspace::factory()->create();
        $team = $workspace->team;
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->members()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user);

        return [$user, $workspace];
    }

    private function configurePostsyncer(Workspace $workspace, ?array $postTypes = null): void
    {
        PostsyncerConfig::write($workspace, [
            'publish_enabled' => true,
            'api_key' => 'test-api-key',
            'languages' => [
                'bangla' => ['workspace_id' => '15211', 'platforms' => []],
                'english' => ['workspace_id' => '853', 'platforms' => []],
            ],
            'post_types' => $postTypes ?? [
                'platforms' => [
                    'threads' => ['text' => 'on', 'photo' => 'ask'],
                ],
                'overrides' => [
                    'english' => [
                        'threads' => ['photo' => 'ask'],
                    ],
                ],
            ],
        ]);
    }

    public function test_guests_cannot_publish_posts(): void
    {
        $post = Post::factory()->create();

        $this->post(route('dashboard.posts.publish', $post))
            ->assertRedirect(route('login'));
    }

    public function test_publish_dispatches_job_and_sets_queued_state(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $this->configurePostsyncer($workspace);

        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'idle',
        ]);

        $response = $this->post(route('dashboard.posts.publish', $post), [
            'when' => '2026-08-26T10:00:00+06:00',
            'confirm_ask' => true,
        ]);

        $response->assertRedirect(route('dashboard.posts.show', $post));

        $this->assertSame('queued', $post->fresh()->publish_state);
        $this->assertNull($post->fresh()->publish_error);

        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($post) {
            return $job->post->is($post)
                && $job->options['when'] === '2026-08-26T10:00:00+06:00'
                && $job->options['confirm_ask'] === true;
        });
    }

    public function test_publish_with_confirm_ask_accepts_ask_gated_platforms(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $this->configurePostsyncer($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['threads'],
            'captions' => ['threads' => 'English threads caption'],
            'image_drive_urls' => ['https://drive.google.com/file/d/photo/view'],
            'publish_state' => 'idle',
        ]);

        $response = $this->post(route('dashboard.posts.publish', $post), [
            'confirm_ask' => true,
        ]);

        $response->assertRedirect(route('dashboard.posts.show', $post));

        $this->assertSame('queued', $post->fresh()->publish_state);

        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($post) {
            return $job->post->is($post)
                && ($job->options['when'] ?? null) === null
                && $job->options['confirm_ask'] === true;
        });
    }

    public function test_publish_now_passes_null_when(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $this->configurePostsyncer($workspace);

        $post = Post::factory()->for($workspace)->create();

        $this->post(route('dashboard.posts.publish', $post), [
            'when' => null,
        ])->assertRedirect(route('dashboard.posts.show', $post));

        Queue::assertPushed(PublishPostJob::class, function (PublishPostJob $job) use ($post) {
            return $job->post->is($post)
                && ($job->options['when'] ?? null) === null;
        });
    }

    public function test_publish_404s_for_post_in_another_workspace(): void
    {
        Queue::fake();
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $post = Post::factory()->for($otherWorkspace)->create();

        $this->post(route('dashboard.posts.publish', $post))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_publish_rejects_when_postsyncer_not_ready(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $post = Post::factory()->for($workspace)->create();

        $this->post(route('dashboard.posts.publish', $post))
            ->assertRedirect()
            ->assertSessionHasErrors('publish');

        Queue::assertNothingPushed();
        $this->assertSame('idle', $post->fresh()->publish_state);
    }

    public function test_publish_rejects_when_already_queued(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $this->configurePostsyncer($workspace);

        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'queued',
        ]);

        $this->post(route('dashboard.posts.publish', $post))
            ->assertRedirect()
            ->assertSessionHasErrors('publish');

        Queue::assertNothingPushed();
    }
}
