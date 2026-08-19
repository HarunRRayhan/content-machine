<?php

namespace Tests\Unit\Concerns;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\HistoryFixture;
use Tests\TestCase;

class RecordsHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('history_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('history_fixtures');

        parent::tearDown();
    }

    public function test_recording_a_status_transition_writes_one_row_with_the_right_shape()
    {
        $fixture = HistoryFixture::create(['name' => 'idea']);

        $transition = $fixture->recordStatusTransition('ideation', 'pending');

        $this->assertDatabaseCount('status_transitions', 1);
        $this->assertSame('ideation', $transition->from);
        $this->assertSame('pending', $transition->to);
        $this->assertSame('system', $transition->actor_type);
        $this->assertNull($transition->actor_id);
        $this->assertSame(HistoryFixture::class, $transition->subject_type);
        $this->assertSame($fixture->id, $transition->subject_id);
    }

    public function test_recording_a_status_transition_resolves_the_authenticated_user_as_actor()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $fixture = HistoryFixture::create(['name' => 'idea']);
        $transition = $fixture->recordStatusTransition(null, 'ready');

        $this->assertSame('user', $transition->actor_type);
        $this->assertSame($user->id, $transition->actor_id);
    }

    public function test_two_field_changes_produce_two_rows_not_one_updated_row()
    {
        $fixture = HistoryFixture::create(['name' => 'idea']);

        $fixture->recordFieldChange('title', 'Old title', 'New title');
        $fixture->recordFieldChange('title', 'New title', 'Newer title');

        $this->assertDatabaseCount('content_versions', 2);

        $versions = $fixture->contentVersions()->orderBy('id')->get();
        $this->assertSame('Old title', $versions[0]->old_value);
        $this->assertSame('New title', $versions[0]->new_value);
        $this->assertSame('New title', $versions[1]->old_value);
        $this->assertSame('Newer title', $versions[1]->new_value);
    }
}
