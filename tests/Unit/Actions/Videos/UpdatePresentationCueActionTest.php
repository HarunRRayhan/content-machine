<?php

use App\Actions\Videos\UpdatePresentationCueAction;
use App\Data\Videos\UpdatePresentationCueData;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('updates the script and map based deck cue', function (): void {
    $video = Video::factory()->create([
        'script_markdown' => "```\n[HOOK]\nFirst spoken line\nSecond spoken line\n```",
        'deck_manifest' => [
            'js' => "const LINES=['First spoken line','Second spoken line']; window.PRESENTATIONS['test']={steps:LINES.map(function(line){return {cue:line};})};",
        ],
    ]);

    (new UpdatePresentationCueAction)->handle($video, new UpdatePresentationCueData(
        step: 1,
        currentCue: 'Second spoken line',
        cue: 'Updated spoken line',
    ));

    $video->refresh();

    $this->assertStringContainsString('Updated spoken line', $video->script_markdown);
    $this->assertStringNotContainsString('Second spoken line', $video->script_markdown);
    $this->assertStringContainsString("'Updated spoken line'", $video->deck_manifest['js']);
});

it('rejects a cue that belongs to another step', function (): void {
    $script = "```\nFirst spoken line\nSecond spoken line\n```";
    $video = Video::factory()->create([
        'script_markdown' => $script,
        'deck_manifest' => [
            'js' => "const LINES=['First spoken line','Second spoken line']; window.PRESENTATIONS['test']={steps:LINES.map(function(line){return {cue:line};})};",
        ],
    ]);

    expect(fn () => (new UpdatePresentationCueAction)->handle($video, new UpdatePresentationCueData(
        step: 1,
        currentCue: 'First spoken line',
        cue: 'Updated spoken line',
    )))->toThrow(InvalidArgumentException::class, 'Presentation step no longer matches its deck cue.');

    $this->assertDatabaseHas('videos', [
        'id' => $video->id,
        'script_markdown' => $script,
    ]);
});

it('escapes a double quoted deck cue', function (): void {
    $video = Video::factory()->create([
        'script_markdown' => "```\nFirst spoken line\n```",
        'deck_manifest' => [
            'js' => 'window.PRESENTATIONS["test"]={steps:[{cue:"First spoken line"}]};',
        ],
    ]);

    (new UpdatePresentationCueAction)->handle($video, new UpdatePresentationCueData(
        step: 0,
        currentCue: 'First spoken line',
        cue: '</script><script>alert(1)</script>',
    ));

    $video->refresh();

    $this->assertStringContainsString('\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E', $video->deck_manifest['js']);
    $this->assertStringNotContainsString('</script><script>alert(1)</script>', $video->deck_manifest['js']);
});

it('handles brackets and comments in a static deck', function (): void {
    $video = Video::factory()->create([
        'script_markdown' => "```\nFirst ] spoken line\n```",
        'deck_manifest' => [
            'js' => "// cue:'not a step'\nwindow.PRESENTATIONS['test']={steps:[{cue:'First ] spoken line'}]};",
        ],
    ]);

    (new UpdatePresentationCueAction)->handle($video, new UpdatePresentationCueData(
        step: 0,
        currentCue: 'First ] spoken line',
        cue: 'Updated line',
    ));

    $video->refresh();

    $this->assertStringContainsString("cue:'Updated line'", $video->deck_manifest['js']);
});

it('rejects positional fallback when script and deck lengths differ', function (): void {
    $script = "```\nFirst spoken line\nSecond spoken line\nThird spoken line\n```";
    $video = Video::factory()->create([
        'script_markdown' => $script,
        'deck_manifest' => [
            'js' => "window.PRESENTATIONS['test']={steps:[{cue:'First cue'},{cue:'Second cue'}]};",
        ],
    ]);

    expect(fn () => (new UpdatePresentationCueAction)->handle($video, new UpdatePresentationCueData(
        step: 1,
        currentCue: 'Second cue',
        cue: 'Updated line',
    )))->toThrow(InvalidArgumentException::class, 'Presentation step no longer matches its script line.');
});

it('does not discard speech around an inline direction', function (): void {
    $script = "```\nBefore [on-screen: card] after\n```";
    $video = Video::factory()->create([
        'script_markdown' => $script,
        'deck_manifest' => [
            'js' => "window.PRESENTATIONS['test']={steps:[{cue:'Before after'}]};",
        ],
    ]);

    expect(fn () => (new UpdatePresentationCueAction)->handle($video, new UpdatePresentationCueData(
        step: 0,
        currentCue: 'Before after',
        cue: 'Updated line',
    )))->toThrow(InvalidArgumentException::class, 'Cannot edit a script line with an inline direction.');

    $this->assertDatabaseHas('videos', [
        'id' => $video->id,
        'script_markdown' => $script,
    ]);
});

it('keeps note-only deck steps in the step index', function (): void {
    $video = Video::factory()->create([
        'script_markdown' => "```\nFirst spoken line\nSecond spoken line\n```",
        'deck_manifest' => [
            'js' => "window.PRESENTATIONS['test']={steps:[{note:'visual only'},{cue:'Second spoken line'}]};",
        ],
    ]);

    (new UpdatePresentationCueAction)->handle($video, new UpdatePresentationCueData(
        step: 1,
        currentCue: 'Second spoken line',
        cue: 'Updated second line',
    ));

    $video->refresh();

    $this->assertStringContainsString('First spoken line', $video->script_markdown);
    $this->assertStringContainsString('Updated second line', $video->script_markdown);
    $this->assertStringContainsString("note:'visual only'", $video->deck_manifest['js']);
    $this->assertStringContainsString("cue:'Updated second line'", $video->deck_manifest['js']);
});
