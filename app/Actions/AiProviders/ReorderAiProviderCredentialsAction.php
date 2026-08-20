<?php

namespace App\Actions\AiProviders;

use App\Data\AiProviders\ReorderAiProviderCredentialsData;
use App\Models\AiProviderCredential;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @throws RuntimeException if $data->orderedIds doesn't name exactly this
 *                          workspace's own credentials (a stale UI, or a
 *                          tampered request naming another workspace's id)
 */
class ReorderAiProviderCredentialsAction
{
    public function handle(Workspace $workspace, ReorderAiProviderCredentialsData $data): void
    {
        $ownIds = AiProviderCredential::query()
            ->where('workspace_id', $workspace->id)
            ->pluck('id')
            ->all();

        if (array_diff($ownIds, $data->orderedIds) !== [] || array_diff($data->orderedIds, $ownIds) !== []) {
            throw new RuntimeException('That order no longer matches this workspace\'s credentials.');
        }

        DB::transaction(function () use ($data) {
            foreach ($data->orderedIds as $priority => $id) {
                AiProviderCredential::query()->whereKey($id)->update(['priority' => $priority]);
            }
        });
    }
}
