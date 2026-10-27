<?php

namespace Tests\Unit\Support\Content;

use App\Support\Content\NormalizeCaptions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NormalizeCaptionsTest extends TestCase
{
    #[Test]
    public function it_returns_empty_for_null_or_empty(): void
    {
        $this->assertSame([], NormalizeCaptions::forDashboard(null));
        $this->assertSame([], NormalizeCaptions::forDashboard([]));
    }

    #[Test]
    public function it_normalizes_import_shape_keyed_by_part_and_platform(): void
    {
        $raw = [
            'Part 7' => [
                'tiktok' => [
                    'title' => 'Hook',
                    'caption' => 'Body text',
                    'first_comment' => 'follow',
                    'images' => ['a.png'],
                ],
                'youtube' => [
                    'title' => 'YT',
                    'caption' => 'Longer',
                ],
            ],
            'main' => [
                'facebook' => [
                    'caption' => 'fb only',
                ],
            ],
        ];

        $out = NormalizeCaptions::forDashboard($raw);

        $this->assertCount(2, $out);
        $this->assertSame('Part 7', $out[0]['part']);
        $this->assertSame('tiktok', $out[0]['platforms'][0]['name']);
        $this->assertSame('Hook', $out[0]['platforms'][0]['title']);
        $this->assertSame('Body text', $out[0]['platforms'][0]['caption']);
        $this->assertSame(['a.png'], $out[0]['platforms'][0]['images']);
        $this->assertNull($out[1]['part']);
        $this->assertSame('facebook', $out[1]['platforms'][0]['name']);
    }

    #[Test]
    public function it_passes_through_studio_shaped_lists(): void
    {
        $raw = [
            [
                'part' => 'Part 1',
                'platforms' => [
                    ['name' => 'TikTok', 'title' => 'T', 'caption' => 'C'],
                ],
            ],
        ];

        $out = NormalizeCaptions::forDashboard($raw);

        $this->assertSame('Part 1', $out[0]['part']);
        $this->assertSame('TikTok', $out[0]['platforms'][0]['name']);
        $this->assertSame('T', $out[0]['platforms'][0]['title']);
    }

    #[Test]
    public function it_keeps_an_explicit_motion_list_and_omits_it_when_absent(): void
    {
        $out = NormalizeCaptions::forDashboard([
            'English' => [
                'instagram' => [
                    'caption' => 'IG',
                    'images' => ['cover.png'],
                    'motion' => ['slide-2.mp4', 'slide-1.mp4'],
                ],
                'facebook' => ['caption' => 'FB'],
                'twitter' => ['caption' => 'X', 'motion' => []],
            ],
        ]);

        $platforms = $out[0]['platforms'];
        $this->assertSame(['slide-2.mp4', 'slide-1.mp4'], $platforms[0]['motion']);
        $this->assertArrayNotHasKey('motion', $platforms[1]);
        $this->assertSame([], $platforms[2]['motion']);
    }
}
