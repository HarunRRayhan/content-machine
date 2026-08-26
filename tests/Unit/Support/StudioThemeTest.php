<?php

namespace Tests\Unit\Support;

use Tests\TestCase;

class StudioThemeTest extends TestCase
{
    public function test_studio_pages_reuse_the_dashboard_tailwind_tokens(): void
    {
        $css = file_get_contents(resource_path('css/studio.css'));

        $this->assertIsString($css);
        $this->assertStringNotContainsString('#efe7d8', $css);
        $this->assertStringNotContainsString('#faf5ea', $css);
        $this->assertStringNotContainsString('Kohinoor Bangla', $css);
        $this->assertStringContainsString('--bg: var(--background)', $css);
        $this->assertStringContainsString('--ink: var(--foreground)', $css);
        $this->assertStringContainsString('font-family: var(--font-sans)', $css);
    }
}
