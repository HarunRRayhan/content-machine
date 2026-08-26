<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

class StudioThemeTest extends TestCase
{
    public function test_studio_pages_use_the_script_studio_paper_theme(): void
    {
        $css = file_get_contents(resource_path('css/studio.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('#efe7d8', $css);
        $this->assertStringContainsString('#faf5ea', $css);
        $this->assertStringContainsString('Kohinoor Bangla', $css);
        $this->assertStringContainsString("ui-serif, 'New York', Georgia", $css);
        $this->assertStringContainsString('--accent: #c23a22', $css);
        $this->assertStringContainsString('font-family: var(--font-bn)', $css);
    }

    public function test_the_dashboard_palette_matches_script_studio_paper(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('--background: #efe7d8', $css);
        $this->assertStringContainsString('--foreground: #2a2119', $css);
        $this->assertStringContainsString('--primary: #c23a22', $css);
        $this->assertStringContainsString('Kohinoor Bangla', $css);
    }
}
