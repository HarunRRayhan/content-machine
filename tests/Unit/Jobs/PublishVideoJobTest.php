<?php

namespace Tests\Unit\Jobs;

use App\Actions\Postsyncer\PublishVideoAction;
use App\Jobs\PublishVideoJob;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PublishVideoJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_delegates_to_the_action_with_options(): void
    {
        $video = Video::factory()->create();
        $options = ['when' => '2026-08-26T09:12:00+06:00', 'confirm_ask' => true];

        $action = Mockery::mock(PublishVideoAction::class);
        $action->shouldReceive('handle')->once()->with(
            Mockery::on(fn (Video $v) => $v->is($video)),
            $options,
        );

        (new PublishVideoJob($video, $options))->handle($action);
    }
}
