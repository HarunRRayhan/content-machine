<?php

namespace App\Data\Videos;

use Illuminate\Http\Request;

final readonly class SaveVideoSeriesData
{
    /** @param list<string> $videoIds */
    public function __construct(
        public string $slug,
        public string $title,
        public array $videoIds,
    ) {}

    public static function fromRequest(Request $request, string $slug): self
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'videos' => ['required', 'array', 'min:1'],
            'videos.*' => ['required', 'string', 'max:32', 'distinct'],
        ]);

        return new self($slug, $validated['title'], array_values($validated['videos']));
    }
}
