<?php

namespace Tests\Unit\Data\Scratchpad;

use App\Data\Scratchpad\CaptureTextNoteData;
use App\Http\Requests\Scratchpad\StoreScratchpadTextNoteRequest;
use Tests\TestCase;

class CaptureTextNoteDataTest extends TestCase
{
    public function test_from_request_reads_the_body()
    {
        $request = StoreScratchpadTextNoteRequest::create('/scratchpad', 'POST', [
            'body' => 'A captured thought.',
        ]);

        $data = CaptureTextNoteData::fromRequest($request);

        $this->assertSame('A captured thought.', $data->body);
        $this->assertSame('web', $data->source);
    }

    public function test_from_telegram_sets_the_telegram_source()
    {
        $data = CaptureTextNoteData::fromTelegram('A message.');

        $this->assertSame('A message.', $data->body);
        $this->assertSame('telegram', $data->source);
    }
}
