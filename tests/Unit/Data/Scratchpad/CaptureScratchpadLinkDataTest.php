<?php

namespace Tests\Unit\Data\Scratchpad;

use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Http\Requests\Scratchpad\StoreScratchpadLinkRequest;
use Tests\TestCase;

class CaptureScratchpadLinkDataTest extends TestCase
{
    public function test_from_request_reads_the_url()
    {
        $request = StoreScratchpadLinkRequest::create('/dashboard/scratchpad/link', 'POST', [
            'url' => 'https://example.com/reel/123',
        ]);

        $data = CaptureScratchpadLinkData::fromRequest($request);

        $this->assertSame('https://example.com/reel/123', $data->url);
    }
}
