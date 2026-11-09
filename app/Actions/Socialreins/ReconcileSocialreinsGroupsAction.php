<?php

namespace App\Actions\Socialreins;

use App\Models\SocialreinsGroup;
use App\Support\Socialreins\SocialreinsClient;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use App\Support\Socialreins\SocialreinsResults;

/**
 * The fallback for a missed webhook, and the source of truth for a group's settled state:
 * `GET /post-groups/{id}` lists every post with all its targets and `expected_targets`, which
 * no webhook does. A
 * group that still has a post in flight is checked again every run.
 */
class ReconcileSocialreinsGroupsAction
{
    public function __construct(
        private readonly SettleSocialreinsSubjectAction $settle,
    ) {}

    /** @return int groups that were checked */
    public function handle(int $olderThanMinutes = 2): int
    {
        $checked = 0;

        $groups = SocialreinsGroup::query()
            ->where('state', SocialreinsGroup::CREATED)
            ->where('updated_at', '<=', now()->subMinutes($olderThanMinutes))
            ->get()
            ->filter(fn (SocialreinsGroup $group): bool => in_array(
                SocialreinsResults::WAITING,
                array_column(SocialreinsResults::byPlatform([$group]), 'state'),
                true,
            ));
        // `byPlatform` calls a group that has not fully reported WAITING, so a group whose
        // events all arrived but whose group read never did is still checked.

        foreach ($groups as $group) {
            if ($this->reconcileGroup($group)) {
                $checked++;
                $subject = $group->subject();
                if ($subject !== null) {
                    $this->settle->handle($subject);
                }
            }
        }

        return $checked;
    }

    /**
     * Merge brain's current view of one group into its stored results. The snapshot time is
     * taken before the request, so a webhook that lands while it is in flight is never
     * overwritten by it. Does not settle the subject.
     *
     * @return bool false when brain could not be asked
     */
    public function reconcileGroup(SocialreinsGroup $group): bool
    {
        $client = new SocialreinsClient(SocialreinsConfig::fromWorkspace($group->workspace), $group->language);
        $at = time();

        try {
            $body = $client->group((string) $group->group_id);
        } catch (SocialreinsException) {
            return false; // the next run tries again
        }

        $group->mutate(function (SocialreinsGroup $locked) use ($body, $at): void {
            $results = $locked->results ?? [];
            foreach ($body['posts'] as $post) {
                if (is_array($post) && is_string($post['id'] ?? null) && isset($results[$post['id']])) {
                    $results[$post['id']] = SocialreinsResults::mergePost(
                        $results[$post['id']],
                        SocialreinsResults::fromBrainPost($post, $at),
                    );
                }
            }
            $locked->results = $results;
            $locked->expected_targets = $body['expected_targets'];
        });

        return true;
    }
}
