<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptureTextNoteActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_new_text_entry()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();

        $entry = (new CaptureTextNoteAction)->handle(
            $workspace,
            $user,
            new CaptureTextNoteData(body: 'Remember to follow up on this.'),
        );

        $this->assertSame($workspace->id, $entry->workspace_id);
        $this->assertSame('text', $entry->kind);
        $this->assertSame('web', $entry->source);
        $this->assertSame('new', $entry->status);
        $this->assertSame('Remember to follow up on this.', $entry->body);
        $this->assertNotNull($entry->captured_at);
        $this->assertNotNull($entry->public_id);
    }

    public function test_it_records_a_null_to_new_status_transition()
    {
        $workspace = Workspace::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = (new CaptureTextNoteAction)->handle(
            $workspace,
            $user,
            new CaptureTextNoteData(body: 'A note.'),
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

    public function test_a_telegram_capture_has_no_capturing_user_and_records_a_system_actor()
    {
        $workspace = Workspace::factory()->create();

        $entry = (new CaptureTextNoteAction)->handle(
            $workspace,
            null,
            CaptureTextNoteData::fromTelegram('A message from the bot.'),
        );

        $this->assertSame('telegram', $entry->source);
        $this->assertDatabaseHas('status_transitions', [
            'subject_type' => $entry->getMorphClass(),
            'subject_id' => $entry->id,
            'actor_type' => 'system',
            'actor_id' => null,
        ]);
    }
}
