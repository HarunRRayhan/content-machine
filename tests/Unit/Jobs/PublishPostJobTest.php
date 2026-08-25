<?php

namespace Tests\Unit\Jobs;

use App\Actions\Postsyncer\PublishPostAction;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PublishPostJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_delegates_to_the_action_with_options(): void
    {
        $post = Post::factory()->create();
        $options = ['when' => '2026-08-26T09:12:00+06:00', 'confirm_ask' => true];

        $action = Mockery::mock(PublishPostAction::class);
        $action->shouldReceive('handle')->once()->with(
            Mockery::on(fn (Post $p) => $p->is($post)),
            $options,
        );

        (new PublishPostJob($post, $options))->handle($action);
    }
}
