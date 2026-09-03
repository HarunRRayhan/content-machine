<?php

use App\Data\Videos\UpdatePresentationCueData;
use App\Http\Requests\Videos\UpdatePresentationCueRequest;
use Tests\TestCase;

uses(TestCase::class);

it('reads step and cues from the request', function (): void {
    $request = UpdatePresentationCueRequest::create('/videos/1/presentation/cue', 'PATCH', [
        'step' => '3',
        'current_cue' => 'Current line',
        'cue' => 'Updated line',
    ]);

    $data = UpdatePresentationCueData::fromRequest($request);

    expect($data->step)->toBe(3)
        ->and($data->currentCue)->toBe('Current line')
        ->and($data->cue)->toBe('Updated line');
});
