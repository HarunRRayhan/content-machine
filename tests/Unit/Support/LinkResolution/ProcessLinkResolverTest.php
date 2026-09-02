<?php

namespace Tests\Unit\Support\LinkResolution;

use App\Support\LinkResolution\ProcessLinkResolver;
use App\Support\LinkResolution\PublicUrlGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ProcessLinkResolverTest extends TestCase
{
    public function test_it_resolves_via_yt_dlp_when_it_succeeds()
    {
        Process::fake([
            '*' => Process::result(output: json_encode([
                'title' => 'A great clip',
                'description' => 'Someone doing something interesting.',
                'thumbnail' => 'https://example.com/thumb.jpg',
            ]).PHP_EOL),
        ]);

        $resolved = (new ProcessLinkResolver)->resolve('https://youtube.com/watch?v=video-1');

        $this->assertSame('video', $resolved->kind);
        $this->assertSame('yt-dlp metadata', $resolved->resolvedVia);
        $this->assertSame('A great clip', $resolved->title);
        $this->assertSame('Someone doing something interesting.', $resolved->description);
        $this->assertSame('https://example.com/thumb.jpg', $resolved->thumbnailUrl);
    }

    public function test_it_falls_back_to_page_metadata_when_yt_dlp_fails()
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'ERROR: unsupported URL', exitCode: 1),
        ]);

        Http::fake([
            '*' => Http::response(
                <<<'HTML'
                <html><head>
                <meta property="og:title" content="A Great Article" />
                <meta property="og:description" content="What it's about." />
                <meta property="og:image" content="https://example.com/cover.jpg" />
                </head></html>
                HTML,
                200,
                ['Content-Type' => 'text/html; charset=utf-8'],
            ),
        ]);

        $resolved = (new ProcessLinkResolver)->resolve('https://example.com/article');

        $this->assertSame('webpage', $resolved->kind);
        $this->assertSame('page metadata (og tags)', $resolved->resolvedVia);
        $this->assertSame('A Great Article', $resolved->title);
        $this->assertSame("What it's about.", $resolved->description);
        $this->assertSame('https://example.com/cover.jpg', $resolved->thumbnailUrl);
    }

    public function test_it_falls_back_to_the_title_tag_when_no_og_tags_exist()
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        Http::fake([
            '*' => Http::response(
                '<html><head><title>Just A Page</title></head></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $resolved = (new ProcessLinkResolver)->resolve('https://example.com/plain');

        $this->assertSame('webpage', $resolved->kind);
        $this->assertSame('page metadata (title tag only)', $resolved->resolvedVia);
        $this->assertSame('Just A Page', $resolved->title);
        $this->assertNull($resolved->description);
    }

    public function test_it_reports_unresolved_when_every_rung_fails()
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        Http::fake(['*' => Http::response('', 404)]);

        $resolved = (new ProcessLinkResolver)->resolve('https://example.com/gone');

        $this->assertSame('unresolved', $resolved->kind);
        $this->assertSame('metadata only (page fetch failed)', $resolved->resolvedVia);
        $this->assertNull($resolved->title);
        $this->assertNull($resolved->description);
    }

    public function test_it_reports_unresolved_when_the_page_has_no_readable_title_or_description()
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        Http::fake([
            '*' => Http::response('<html><body>no head at all</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $resolved = (new ProcessLinkResolver)->resolve('https://example.com/empty');

        $this->assertSame('unresolved', $resolved->kind);
        $this->assertSame('metadata only (no readable title or description)', $resolved->resolvedVia);
    }

    public function test_it_does_not_buffer_an_oversized_html_page(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        Http::fake([
            '*' => Http::response(
                str_repeat('x', 1024 * 1024 + 1),
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        $guard = new PublicUrlGuard(
            fn (string $host): array => [['ip' => '1.1.1.1']],
        );
        $resolved = (new ProcessLinkResolver($guard))->resolve('https://example.test/large');

        $this->assertSame('unresolved', $resolved->kind);
        $this->assertSame('metadata only (page too large)', $resolved->resolvedVia);
    }
}
