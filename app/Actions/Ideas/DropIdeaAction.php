<?php

namespace App\Actions\Ideas;

use App\Data\Ideas\DropIdeaData;
use App\Models\Idea;
use RuntimeException;

/**
 * Drops an idea directly (the "'scratch that' / 'drop it'" flow applying
 * to an idea itself, not only via scratchpad triage). Guards against
 * dropping an already-dropped idea the same way
 * TriageScratchpadEntryAction guards against re-triaging an entry.
 *
 * @throws RuntimeException if the idea is already dropped
 */
class DropIdeaAction
{
    public function handle(Idea $idea, DropIdeaData $data): Idea
    {
        if ($idea->status === 'dropped') {
            throw new RuntimeException('This idea has already been dropped.');
        }

        $from = $idea->status;

        $idea->forceFill([
            'status' => 'dropped',
            'drop_reason' => $data->dropReason,
        ])->save();

        $idea->recordStatusTransition($from, 'dropped', $data->dropReason);

        return $idea;
    }
}
