<?php

namespace Tests\Unit\Actions\Scratchpad;

use App\Actions\Scratchpad\ResolveScratchpadLinkAction;
use App\Models\ScratchpadEntry;
use App\Models\Workspace;
use App\Support\LinkResolution\LinkResolverContract;
use App\Support\LinkResolution\ResolvedLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveScratchpadLinkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_title_body_and_meta_from_a_successful_resolution()
    {
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => Workspace::factory(),
            'kind' => 'link',
            'body' => 'https://example.com/article',
            'meta' => ['url' => 'https://example.com/article'],
        ]);

        $resolver = new class implements LinkResolverContract
        {
            public function resolve(string $url): ResolvedLink
            {
                return new ResolvedLink(
                    kind: 'webpage',
                    resolvedVia: 'page metadata (og tags)',
                    title: 'A Great Article',
                    description: 'What the article is about.',
                    thumbnailUrl: 'https://example.com/cover.jpg',
                );
            }
        };

        (new ResolveScratchpadLinkAction($resolver))->handle($entry);
        $entry->refresh();

        $this->assertSame('A Great Article', $entry->title);
        $this->assertSame('What the article is about.', $entry->body);
        $this->assertSame('https://example.com/article', $entry->meta['url']);
        $this->assertSame('page metadata (og tags)', $entry->meta['resolved_via']);
        $this->assertSame('webpage', $entry->meta['resolved_kind']);
        $this->assertSame('https://example.com/cover.jpg', $entry->meta['thumbnail_url']);
        $this->assertNotNull($entry->meta['resolved_at']);
    }

    public function test_an_unresolved_link_keeps_the_url_as_body_and_records_why()
    {
        $entry = ScratchpadEntry::factory()->create([
            'workspace_id' => Workspace::factory(),
            'kind' => 'link',
            'body' => 'https://example.com/login-walled',
            'meta' => ['url' => 'https://example.com/login-walled'],
        ]);

        $resolver = new class implements LinkResolverContract
        {
            public function resolve(string $url): ResolvedLink
            {
                return ResolvedLink::unresolved('metadata only (page fetch failed)');
            }
        };

        (new ResolveScratchpadLinkAction($resolver))->handle($entry);
        $entry->refresh();

        $this->assertNull($entry->title);
        $this->assertSame('https://example.com/login-walled', $entry->body);
        $this->assertSame('metadata only (page fetch failed)', $entry->meta['resolved_via']);
        $this->assertSame('unresolved', $entry->meta['resolved_kind']);
    }
}
