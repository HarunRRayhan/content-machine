<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\UpdateScratchpadEntryAction;
use App\Data\Scratchpad\UpdateScratchpadEntryData;
use App\Models\ContentVersion;
use App\Models\ScratchpadEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class UpdateScratchpadEntryActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_only_the_fields_sent()
    {
        $entry = ScratchpadEntry::factory()->create([
            'kind' => 'voice',
            'title' => null,
            'body' => 'original',
            'language' => 'bn',
        ]);

        (new UpdateScratchpadEntryAction)->handle(
            $entry,
            new UpdateScratchpadEntryData(title: 'Fixed title', body: null, language: null),
        );

        $fresh = $entry->fresh();

        $this->assertSame('Fixed title', $fresh->title);
        $this->assertSame('original', $fresh->body);
        $this->assertSame('bn', $fresh->language);
    }

    public function test_it_records_a_version_for_each_changed_field_and_none_for_unchanged_ones()
    {
        $entry = ScratchpadEntry::factory()->create([
            'kind' => 'text',
            'title' => 'Same title',
            'body' => 'old body',
        ]);

        (new UpdateScratchpadEntryAction)->handle(
            $entry,
            new UpdateScratchpadEntryData(title: 'Same title', body: 'new body', language: 'bn'),
        );

        $fields = ContentVersion::query()->orderBy('field')->pluck('field')->all();

        $this->assertSame(['body', 'language'], $fields);

        $bodyChange = ContentVersion::query()->where('field', 'body')->sole();
        $this->assertSame('old body', $bodyChange->old_value);
        $this->assertSame('new body', $bodyChange->new_value);
    }

    public function test_an_identical_patch_writes_no_versions()
    {
        $entry = ScratchpadEntry::factory()->create([
            'kind' => 'text',
            'title' => 'unchanged',
        ]);

        (new UpdateScratchpadEntryAction)->handle(
            $entry,
            new UpdateScratchpadEntryData(title: 'unchanged', body: null, language: null),
        );

        $this->assertSame(0, ContentVersion::query()->count());
    }

    public function test_it_refuses_to_edit_a_dropped_entry()
    {
        $entry = ScratchpadEntry::factory()->dropped()->create(['kind' => 'text']);

        $this->expectException(RuntimeException::class);

        (new UpdateScratchpadEntryAction)->handle(
            $entry,
            new UpdateScratchpadEntryData(title: 'zombie', body: null, language: null),
        );
    }
}
