<?php

namespace Tests\Unit\Support\Publishing;

use App\Support\Publishing\PlanningConfig;
use Tests\TestCase;

class PlanningConfigTest extends TestCase
{
    public function test_languages_default_to_those_with_a_set_and_lead_with_the_default(): void
    {
        $config = PlanningConfig::fromArray([
            'default_language' => 'bangla',
            'languages' => [
                'english' => ['workspace_id' => 'set-en'],
            ],
        ]);

        $this->assertSame('bangla', $config->defaultLanguage());
        $this->assertSame(['bangla', 'english'], $config->enabledLanguages());
        $this->assertSame(['workspace_id' => 'set-en'], $config->language('english'));
        $this->assertSame(['workspace_id' => null], $config->language('bangla'));
    }

    public function test_defaults(): void
    {
        $config = PlanningConfig::fromArray([]);

        $this->assertSame('english', $config->defaultLanguage());
        $this->assertSame(['english'], $config->enabledLanguages());
        $this->assertSame([], $config->postTypes());
    }

    public function test_stored_matrix_fills_only_unset_youtube_image_cells_from_defaults(): void
    {
        $postTypes = PlanningConfig::fromArray([
            'post_types' => [
                'platforms' => [
                    'youtube' => ['reel' => 'on', 'photo' => null, 'carousel' => 'off'],
                    'facebook' => ['text' => 'on'],
                ],
            ],
        ])->postTypes();

        $this->assertSame('on', $postTypes['platforms']['youtube']['reel']);
        $this->assertSame('ask', $postTypes['platforms']['youtube']['photo']);
        $this->assertSame('off', $postTypes['platforms']['youtube']['carousel']);
        $this->assertSame('on', $postTypes['platforms']['facebook']['text']);
        $this->assertArrayNotHasKey('photo', $postTypes['platforms']['facebook']);
    }

    public function test_empty_stored_matrix_is_not_filled(): void
    {
        $this->assertSame([], PlanningConfig::fromArray(['post_types' => []])->postTypes());
    }
}
