<?php

namespace Tests\Unit\Support\Postsyncer;

use App\Support\Postsyncer\ScriptStudioPostTypes;
use Tests\TestCase;

class ScriptStudioPostTypesTest extends TestCase
{
    public function test_defaults_match_script_studio_matrix(): void
    {
        $defaults = ScriptStudioPostTypes::defaults();

        $this->assertSame('on', $defaults['platforms']['facebook']['text']);
        $this->assertSame('on', $defaults['platforms']['youtube']['reel']);
        $this->assertSame('off', $defaults['overrides']['english']['twitter']['photo']);
        $this->assertSame('off', $defaults['overrides']['bangla']['twitter']['text']);
        $this->assertFalse(ScriptStudioPostTypes::isEmpty($defaults));
    }

    public function test_empty_and_blank_states_count_as_empty(): void
    {
        $this->assertTrue(ScriptStudioPostTypes::isEmpty([]));
        $this->assertTrue(ScriptStudioPostTypes::isEmpty([
            'platforms' => [
                'facebook' => ['text' => '', 'photo' => null],
            ],
        ]));
    }
}
