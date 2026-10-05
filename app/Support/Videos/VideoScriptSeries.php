<?php

namespace App\Support\Videos;

use App\Models\Video;
use App\Models\VideoSeries;
use Illuminate\Support\Collection;

final class VideoScriptSeries
{
    public static function includesSeries(?string $markdown): bool
    {
        if (! is_string($markdown) || $markdown === '') {
            return false;
        }

        foreach (preg_split("/\r\n|\n|\r/", $markdown) ?: [] as $line) {
            if (preg_match('/^##\s/', $line) === 1) {
                break;
            }

            if (preg_match('/\*\*Series:\*\*/i', $line) === 1) {
                return true;
            }

            if (preg_match('/\*\*Format:\*\*.*\bseries\b/i', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Video>  $videos
     * @return Collection<int, Video>
     */
    public static function qualifying(Collection $videos): Collection
    {
        return $videos
            ->filter(fn (Video $video): bool => self::includesSeries($video->script_markdown))
            ->values();
    }

    /**
     * @return array{slug: string, title: string, part: int|null, videos: list<array{human_id: string, title: string, part: int|null}>}|null
     */
    public static function forVideo(Video $video): ?array
    {
        $series = $video->series;

        if (! $series instanceof VideoSeries || ! self::includesSeries($video->script_markdown)) {
            return null;
        }

        return [
            'slug' => $series->slug,
            'title' => $series->title,
            'part' => $video->series_part,
            'videos' => self::qualifying($series->videos)->map(fn (Video $part): array => [
                'human_id' => $part->human_id,
                'title' => $part->title,
                'part' => $part->series_part,
            ])->all(),
        ];
    }
}
