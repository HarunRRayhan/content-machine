<?php

namespace App\Console\Commands;

use App\Actions\Socialreins\MoveOffPostsyncerAction;
use App\Models\Post;
use App\Models\Video;
use Illuminate\Console\Command;

class MoveOffPostsyncerCommand extends Command
{
    protected $signature = 'socialreins:move-off-postsyncer
        {--apply : Move the items; without it nothing is changed and the command only lists}
        {--min-minutes=15 : Skip items due sooner than this many minutes}';

    protected $description = 'Move scheduled posts and videos from PostSyncer to SocialReins (dry run unless --apply)';

    public function handle(MoveOffPostsyncerAction $action): int
    {
        $apply = (bool) $this->option('apply');
        $lead = max(0, (int) $this->option('min-minutes'));

        $this->warn('Run postsyncer:sync-scheduled once first (and before switching PostSyncer off), so items PostSyncer already published are marked posted instead of listed here.');
        $this->line($apply ? 'Applying.' : 'Dry run: nothing is changed. Pass --apply to move.');

        $listed = 0;
        foreach ([Post::class, Video::class] as $model) {
            $model::query()
                ->with('workspace')
                ->where(fn ($query) => $query->where('status', 'scheduled')
                    ->orWhereNotNull('publish_progress->'.MoveOffPostsyncerAction::MARKER))
                ->orderBy('id')
                // Not chunked: a move changes the rows this query selects.
                ->get()
                ->each(function (Post|Video $subject) use ($action, $apply, $lead, &$listed): void {
                    $result = $action->handle($subject, $apply, $lead);
                    if ($result === null) {
                        return;
                    }

                    $listed++;
                    $pairs = fn (array $pairs): string => $pairs === [] ? '-' : implode(', ', $pairs);
                    $this->line(sprintf(
                        '%s  %s  postsyncer: %s  to socialreins: %s  left out: %s  => %s',
                        $result->humanId,
                        $result->scheduledAt ?? '-',
                        $pairs($result->postsyncer),
                        $pairs($result->move),
                        $pairs($result->leftOut),
                        $result->outcome,
                    ));
                });
        }

        $this->info("{$listed} item(s) with a PostSyncer part.");

        return self::SUCCESS;
    }
}
