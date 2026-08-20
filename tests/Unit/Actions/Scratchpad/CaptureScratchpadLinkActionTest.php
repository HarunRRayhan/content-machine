<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CaptureScratchpadLinkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_link_entry_with_the_url_in_meta_and_body()
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $entry = (new CaptureScratchpadLinkAction)->handle(
            $workspace,
            $user,
            new CaptureScratchpadLinkData(url: 'https://example.com/reel/123'),
        );

        $this->assertSame($workspace->id, $entry->workspace_id);
        $this->assertSame('link', $entry->kind);
        $this->assertSame('web', $entry->source);
        $this->assertSame('new', $entry->status);
        $this->assertSame('https://example.com/reel/123', $entry->body);
        $this->assertSame('https://example.com/reel/123', $entry->meta['url']);
        $this->assertNotNull($entry->public_id);
    }

    public function test_it_queues_resolution_for_the_created_entry()
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $entry = (new CaptureScratchpadLinkAction)->handle(
            $workspace,
            $user,
            new CaptureScratchpadLinkData(url: 'https://example.com/reel/123'),
        );

        Queue::assertPushed(ResolveScratchpadLinkJob::class, fn (ResolveScratchpadLinkJob $job) => $job->entry->is($entry));
    }

    public function test_it_records_a_null_to_new_status_transition()
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = (new CaptureScratchpadLinkAction)->handle(
            $workspace,
            $user,
            new CaptureScratchpadLinkData(url: 'https://example.com/reel/123'),
        );

        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'from' => null,
            'to' => 'new',
            'actor_type' => 'user',
            'actor_id' => $user->id,
        ]);
    }
}
