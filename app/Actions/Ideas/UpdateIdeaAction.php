<?php

namespace App\Actions\Ideas;

use App\Data\Ideas\UpdateIdeaData;
use App\Models\Idea;

/**
 * Edits an idea's editable fields (title/score/trend/rationale/body, plus
 * source_links/source_text when sent).
 * Doesn't touch kind/number/human_id/slug/status, those are set once at
 * triage time and aren't part of this slice's editing surface, and doesn't
 * record field-level history (content_versions): not asked for by this
 * slice, and RecordsHistory's recordFieldChange is available to add later
 * without changing this Action's shape.
 */
class UpdateIdeaAction
{
    public function handle(Idea $idea, UpdateIdeaData $data): Idea
    {
        $attributes = [
            'title' => $data->title,
            'score' => $data->score,
            'trend' => $data->trend,
            'rationale' => $data->rationale,
            'body' => $data->body,
        ];

        if ($data->hasSourceLinks) {
            $attributes['source_links'] = $data->sourceLinks;
        }

        if ($data->hasSourceText) {
            $attributes['source_text'] = $data->sourceText;
        }

        $idea->forceFill($attributes)->save();

        return $idea;
    }
}
