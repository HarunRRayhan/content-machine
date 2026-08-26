<?php

namespace Tests\Unit\Data\Scratchpad;

use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Http\Requests\Scratchpad\StoreScratchpadLinkRequest;
use Tests\TestCase;

class CaptureScratchpadLinkDataTest extends TestCase
{
    public function test_from_request_reads_the_url()
    {
        $request = StoreScratchpadLinkRequest::create('/scratchpad/link', 'POST', [
            'url' => 'https://example.com/reel/123',
        ]);

        $data = CaptureScratchpadLinkData::fromRequest($request);

        $this->assertSame('https://example.com/reel/123', $data->url);
        $this->assertSame('web', $data->source);
    }

    public function test_from_telegram_sets_the_telegram_source()
    {
        $data = CaptureScratchpadLinkData::fromTelegram('https://example.com/reel/123');

        $this->assertSame('https://example.com/reel/123', $data->url);
        $this->assertSame('telegram', $data->source);
    }
}
