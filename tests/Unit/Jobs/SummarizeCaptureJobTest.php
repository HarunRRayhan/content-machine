<?php

namespace Tests\Unit\Jobs;

use App\Actions\Scratchpad\SummarizeCaptureAction;
use App\Jobs\SummarizeCaptureJob;
use App\Models\ScratchpadEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SummarizeCaptureJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_delegates_to_the_action()
    {
        $entry = ScratchpadEntry::factory()->create();

        $action = Mockery::mock(SummarizeCaptureAction::class);
        $action->shouldReceive('handle')->once()->with(Mockery::on(
            fn (ScratchpadEntry $e) => $e->is($entry)
        ));

        (new SummarizeCaptureJob($entry))->handle($action);
    }
}
