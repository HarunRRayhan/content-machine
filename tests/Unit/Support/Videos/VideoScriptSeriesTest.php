<?php

namespace Tests\Unit\Support\Videos;

use App\Support\Videos\VideoScriptSeries;
use Tests\TestCase;

class VideoScriptSeriesTest extends TestCase
{
    public function test_reads_a_series_only_from_the_script_header(): void
    {
        $this->assertTrue(VideoScriptSeries::includesSeries("**Format:** VPN beginner series, part 1 of 5\n"));
        $this->assertTrue(VideoScriptSeries::includesSeries("> **Series:** Part 1 of 7\n"));
        $this->assertFalse(VideoScriptSeries::includesSeries("**Format:** Standalone beginner explainer\n"));
        $this->assertFalse(VideoScriptSeries::includesSeries("**Format:** Standalone\n\n## Script\n\nthis series continues\n"));
        $this->assertFalse(VideoScriptSeries::includesSeries(null));
    }
}
