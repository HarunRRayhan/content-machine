<?php

namespace App\Actions\Videos;

use App\Data\Videos\SaveVideoSeriesData;
use App\Models\Video;
use App\Models\VideoSeries;
use App\Models\Workspace;
use App\Support\Videos\VideoScriptSeries;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveVideoSeriesAction
{
    public function handle(Workspace $workspace, SaveVideoSeriesData $data): VideoSeries
    {
        return DB::transaction(function () use ($workspace, $data): VideoSeries {
            if (count($data->videoIds) !== count(array_unique($data->videoIds))) {
                throw ValidationException::withMessages(['videos' => 'A video can appear only once in a series.']);
            }

            $series = VideoSeries::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'slug' => $data->slug],
                ['title' => $data->title],
            );
            $series = VideoSeries::query()->whereKey($series->id)->lockForUpdate()->firstOrFail();

            $videos = Video::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('human_id', $data->videoIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('human_id');

            if ($videos->count() !== count($data->videoIds)) {
                throw ValidationException::withMessages(['videos' => 'Every video must exist in this workspace.']);
            }

            foreach ($data->videoIds as $humanId) {
                $video = $videos[$humanId];
                if ($video->series_id !== null && $video->series_id !== $series->id) {
                    throw ValidationException::withMessages(['videos' => "{$humanId} already belongs to another series."]);
                }
                if (! VideoScriptSeries::includesSeries($video->script_markdown)) {
                    throw ValidationException::withMessages(['videos' => "{$humanId} does not include a series."]);
                }
            }

            // Clear old positions first, so swaps do not trip the unique constraint.
            Video::query()->where('series_id', $series->id)->update([
                'series_id' => null,
                'series_part' => null,
            ]);

            foreach ($data->videoIds as $index => $humanId) {
                Video::query()->whereKey($videos[$humanId]->id)->update([
                    'series_id' => $series->id,
                    'series_part' => $index + 1,
                ]);
            }

            $series->forceFill(['title' => $data->title])->save();

            return $series->load('videos');
        });
    }
}
