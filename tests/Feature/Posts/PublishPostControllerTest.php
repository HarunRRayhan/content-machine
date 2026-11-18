<?php

namespace Tests\Feature\Posts;

use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
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

    private function configureForPublish(Workspace $workspace): void
    {
        $this->configureSocialreins($workspace, [
            'platforms' => [
                'threads' => ['text' => 'on', 'photo' => 'ask'],
            ],
            'overrides' => [
                'english' => [
                    'threads' => ['photo' => 'ask'],
                ],
            ],
        ]);
    }

    public function test_guests_cannot_publish_posts(): void
    {
        $post = Post::factory()->create();

        $this->post(route('posts.publish', $post))
            ->assertRedirect(route('login'));
    }

    public function test_publish_dispatches_job_and_sets_queued_state(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $this->configureForPublish($workspace);

        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'idle',
        ]);

        $response = $this->post(route('posts.publish', $post), [
            'when' => '2026-08-26T10:00:00+06:00',
            'confirm_ask' => true,
        ]);

        $response->assertRedirect(route('posts.show', $post));

        $this->assertSame('queued', $post->fresh()->publish_state);
        $this->assertNull($post->fresh()->publish_error);

        Queue::assertPushed(PublishSocialreinsJob::class, function (PublishSocialreinsJob $job) use ($post) {
            return $job->subject->is($post)
                && $job->options['when'] === '2026-08-26T10:00:00+06:00'
                && $job->options['confirm_ask'] === true
                && $post->fresh()->publish_progress['driver'] === 'socialreins';
        });
    }

    public function test_publish_with_confirm_ask_accepts_ask_gated_platforms(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $this->configureForPublish($workspace);

        $post = Post::factory()->for($workspace)->create([
            'language' => 'en',
            'platforms' => ['threads'],
            'captions' => ['threads' => 'English threads caption'],
            'image_drive_urls' => ['https://drive.google.com/file/d/photo/view'],
            'publish_state' => 'idle',
        ]);

        $response = $this->post(route('posts.publish', $post), [
            'confirm_ask' => true,
        ]);

        $response->assertRedirect(route('posts.show', $post));

        $this->assertSame('queued', $post->fresh()->publish_state);

        Queue::assertPushed(PublishSocialreinsJob::class, function (PublishSocialreinsJob $job) use ($post) {
            return $job->subject->is($post)
                && ($job->options['when'] ?? null) === null
                && $job->options['confirm_ask'] === true;
        });
    }

    public function test_publish_now_passes_null_when(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $this->configureForPublish($workspace);

        $post = Post::factory()->for($workspace)->create();

        $this->post(route('posts.publish', $post), [
            'when' => null,
        ])->assertRedirect(route('posts.show', $post));

        Queue::assertPushed(PublishSocialreinsJob::class, function (PublishSocialreinsJob $job) use ($post) {
            return $job->subject->is($post)
                && ($job->options['when'] ?? null) === null;
        });
    }

    public function test_publish_404s_for_post_in_another_workspace(): void
    {
        Queue::fake();
        $this->actingAsWorkspaceMember();

        $otherWorkspace = Workspace::factory()->create();
        $post = Post::factory()->for($otherWorkspace)->create();

        $this->post(route('posts.publish', $post))->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_publish_rejects_when_socialreins_is_not_ready(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $post = Post::factory()->for($workspace)->create();

        $this->post(route('posts.publish', $post))
            ->assertRedirect()
            ->assertSessionHasErrors('publish');

        Queue::assertNothingPushed();
        $this->assertSame('idle', $post->fresh()->publish_state);
    }

    public function test_publish_rejects_when_already_queued(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $this->configureForPublish($workspace);

        $post = Post::factory()->for($workspace)->create([
            'publish_state' => 'queued',
        ]);

        $this->post(route('posts.publish', $post))
            ->assertRedirect()
            ->assertSessionHasErrors('publish');

        Queue::assertNothingPushed();
    }

    public function test_publish_rejects_a_post_published_through_postsyncer(): void
    {
        Queue::fake();

        [, $workspace] = $this->actingAsWorkspaceMember();
        $this->configureForPublish($workspace);

        $post = Post::factory()->for($workspace)->create([
            'status' => 'posted',
            'publish_state' => 'succeeded',
            'postsyncer' => [
                'groups' => [[
                    'post_id' => '133111',
                    'status' => 'PUBLISHED',
                    'platforms' => ['instagram'],
                    'language' => 'english',
                ]],
            ],
        ]);

        $this->post(route('posts.publish', $post))
            ->assertRedirect()
            ->assertSessionHasErrors(['publish' => SocialreinsConfig::POSTSYNCER_HISTORY_MESSAGE]);

        Queue::assertNothingPushed();
        $post->refresh();
        $this->assertSame('succeeded', $post->publish_state);
        $this->assertNull($post->publish_error);
    }
}
