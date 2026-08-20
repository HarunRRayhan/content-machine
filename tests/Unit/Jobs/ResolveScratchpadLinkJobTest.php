<?php

namespace Tests\Unit\Jobs;

use App\Actions\Scratchpad\ResolveScratchpadLinkAction;
use App\Jobs\ResolveScratchpadLinkJob;
use App\Models\ScratchpadEntry;
use App\Models\Workspace;
use App\Support\LinkResolution\LinkResolverContract;
use App\Support\LinkResolution\ResolvedLink;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveScratchpadLinkJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_resolves_the_entry_via_the_action()
    {
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => Workspace::factory(),
            'kind' => 'link',
            'body' => 'https://example.com/post',
            'meta' => ['url' => 'https://example.com/post'],
        ]);

        $resolver = new class implements LinkResolverContract
        {
            public function resolve(string $url): ResolvedLink
            {
                return new ResolvedLink(kind: 'webpage', resolvedVia: 'page metadata (og tags)', title: 'Resolved Title');
            }
        };

        (new ResolveScratchpadLinkJob($entry))->handle(new ResolveScratchpadLinkAction($resolver));
        $entry->refresh();

        $this->assertSame('Resolved Title', $entry->title);
        $this->assertSame('page metadata (og tags)', $entry->meta['resolved_via']);
    }

    public function test_failed_marks_the_entry_as_unresolved_without_losing_the_url()
    {
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => Workspace::factory(),
            'kind' => 'link',
            'body' => 'https://example.com/post',
            'meta' => ['url' => 'https://example.com/post'],
        ]);

        (new ResolveScratchpadLinkJob($entry))->failed(new Exception('yt-dlp binary missing'));
        $entry->refresh();

        $this->assertSame('https://example.com/post', $entry->meta['url']);
        $this->assertSame('metadata only (resolution failed)', $entry->meta['resolved_via']);
        $this->assertSame('unresolved', $entry->meta['resolved_kind']);
    }
}
