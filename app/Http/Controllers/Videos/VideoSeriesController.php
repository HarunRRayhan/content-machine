<?php

namespace App\Http\Controllers\Videos;

use App\Http\Controllers\Controller;
use App\Models\VideoSeries;
use App\Models\Workspace;
use App\Support\Videos\VideoScriptSeries;
use Inertia\Inertia;
use Inertia\Response;

class VideoSeriesController extends Controller
{
    public function index(): Response
    {
        abort_if(Workspace::current() === null, 404);

        return Inertia::render('videos/series/index', [
            'series' => VideoSeries::query()
                ->with('videos:id,series_id,title,series_part,script_markdown')
                ->orderBy('title')
                ->get(['id', 'slug', 'title'])
                ->map(function (VideoSeries $series): array {
                    $videos = VideoScriptSeries::qualifying($series->videos);
                    $preview = [];

                    foreach ($videos->take(3) as $video) {
                        $preview[] = [
                            'part' => $video->series_part,
                            'title' => $video->title,
                        ];
                    }

                    return [
                        'id' => $series->id,
                        'slug' => $series->slug,
                        'title' => $series->title,
                        'videos_count' => $videos->count(),
                        'preview' => $preview,
                    ];
                })
                ->filter(fn (array $series): bool => $series['videos_count'] > 0)
                ->values(),
        ]);
    }

    public function show(string $slug): Response
    {
        abort_if(Workspace::current() === null, 404);

        $series = VideoSeries::query()->where('slug', $slug)->with('videos')->firstOrFail();

        return Inertia::render('videos/series/show', [
            'series' => [
                'slug' => $series->slug,
                'title' => $series->title,
                'videos' => VideoScriptSeries::qualifying($series->videos)->map(fn ($video) => [
                    'human_id' => $video->human_id,
                    'title' => $video->title,
                    'part' => $video->series_part,
                    'status' => $video->status,
                ])->all(),
            ],
        ]);
    }
}
