<?php

namespace Tests\Unit\Data\Scratchpad;

use App\Data\Scratchpad\CaptureTextNoteData;
use App\Http\Requests\Scratchpad\StoreScratchpadTextNoteRequest;
use Tests\TestCase;

class CaptureTextNoteDataTest extends TestCase
{
    public function test_from_request_reads_the_body()
    {
        $request = StoreScratchpadTextNoteRequest::create('/dashboard/scratchpad', 'POST', [
            'body' => 'A captured thought.',
        ]);

        $data = CaptureTextNoteData::fromRequest($request);

        $this->assertSame('A captured thought.', $data->body);
    }
}
