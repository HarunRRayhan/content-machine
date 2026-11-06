<?php

namespace App\Actions\Socialreins;

use App\Models\SocialreinsGroup;
use App\Support\Socialreins\SocialreinsClient;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\SocialreinsException;
use App\Support\Socialreins\SocialreinsResults;

/**
 * The fallback for a missed webhook: `GET /posts?group_id=` for every group that still has
 * a post in flight, folded into the stored results exactly as the webhook would have.
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

        foreach ($groups as $group) {
            $client = new SocialreinsClient(SocialreinsConfig::fromWorkspace($group->workspace), $group->language);

            try {
                $posts = $client->postsOfGroup((string) $group->group_id);
            } catch (SocialreinsException) {
                continue; // next run tries again
            }

            $results = $group->results ?? [];
            foreach ($posts as $post) {
                if (is_string($post['id'] ?? null) && isset($results[$post['id']])) {
                    $results[$post['id']] = [...$results[$post['id']], ...SocialreinsResults::fromBrainPost($post)];
                }
            }

            $group->forceFill(['results' => $results])->save();
            $checked++;

            $subject = $group->subject();
            if ($subject !== null) {
                $this->settle->handle($subject);
            }
        }

        return $checked;
    }
}
