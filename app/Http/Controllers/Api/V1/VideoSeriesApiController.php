<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Videos\SaveVideoSeriesAction;
use App\Data\Videos\SaveVideoSeriesData;
use App\Http\Controllers\Controller;
use App\Models\VideoSeries;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoSeriesApiController extends Controller
{
    public function index(): JsonResponse
    {
        abort_if(Workspace::current() === null, 404);

        return response()->json(['data' => VideoSeries::query()
            ->withCount('videos')
            ->orderBy('title')
            ->get(['id', 'slug', 'title'])]);
    }

    public function show(string $slug): JsonResponse
    {
        abort_if(Workspace::current() === null, 404);

        return response()->json(['data' => $this->present(
            VideoSeries::query()->where('slug', $slug)->with('videos')->firstOrFail(),
        )]);
    }

    public function save(Request $request, string $slug, SaveVideoSeriesAction $action): JsonResponse
    {
        $workspace = Workspace::current();
        abort_if($workspace === null, 404);
        abort_unless((bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug), 404);

        $series = $action->handle($workspace, SaveVideoSeriesData::fromRequest($request, $slug));

        return response()->json(['data' => $this->present($series)]);
    }

    /** @return array<string, mixed> */
    private function present(VideoSeries $series): array
    {
        return [
            'slug' => $series->slug,
            'title' => $series->title,
            'videos' => $series->videos->map(fn ($video) => [
                'human_id' => $video->human_id,
                'title' => $video->title,
                'part' => $video->series_part,
                'status' => $video->status,
            ])->all(),
        ];
    }
}
