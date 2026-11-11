<?php

namespace App\Actions\Socialreins;

use App\Jobs\PublishSocialreinsJob;
use App\Models\Post;
use App\Models\Video;
use App\Support\Socialreins\SocialreinsConfig;

/**
 * The second half of a split publish (SocialreinsConfig::splitOf()). Once PostSyncer has
 * published its platforms, the post or video moves to SocialReins for the rest: from here on
 * it is a SocialReins subject whose retries resend only the SocialReins half, and its publish state
 * is SocialReins', so it reads succeeded only after both halves did.
 */
class ContinueSplitOnSocialreinsAction
{
    /** Call inside the transaction that holds the subject's row lock and just marked it succeeded. */
    public function handle(Post|Video $locked): void
    {
        $split = $locked->publish_progress['split'] ?? null;

        if ($locked->publish_state !== 'succeeded' || ! is_array($split) || ! is_array($split['socialreins'] ?? null)) {
            return;
        }

        $options = $split['socialreins'];
        // The PostSyncer reschedule actions need that half's verified payloads. Kept in the split, which
        // a SocialReins retry carries over, and not at the top, where PostSyncer code would read it.
        $split['completed_groups'] = $locked->publish_progress['completed_groups'] ?? [];

        $locked->forceFill([
            'publish_state' => 'queued',
            'publish_error' => null,
            // Same shape EnqueueSocialreinsPublishAction writes, plus the split it continues.
            'publish_progress' => ['driver' => SocialreinsConfig::DRIVER, 'state' => 'failed', 'options' => $options, 'split' => $split],
            // Only posts carry a PostSyncer claim lease.
            ...($locked instanceof Post ? ['publish_claimed_at' => null, 'publish_lease_id' => null] : []),
        ])->save();

        // The database queue shares this connection: the job commits with the row.
        dispatch(new PublishSocialreinsJob($locked, $options))->beforeCommit();
    }
}
