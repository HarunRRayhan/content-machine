<?php

namespace App\Actions\AiProviders;

use App\Data\AiProviders\ReorderAiProviderCredentialModelsData;
use App\Models\AiProviderCredentialModel;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @throws RuntimeException if $data->orderedIds doesn't name exactly this
 *                          workspace's own models for that purpose (a
 *                          stale UI, or a tampered request naming another
 *                          workspace's row)
 */
class ReorderAiProviderCredentialModelsAction
{
    public function handle(Workspace $workspace, ReorderAiProviderCredentialModelsData $data): void
    {
        $ownIds = AiProviderCredentialModel::query()
            ->where('purpose', $data->purpose)
            ->whereHas('credential', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->pluck('id')
            ->all();

        if (array_diff($ownIds, $data->orderedIds) !== [] || array_diff($data->orderedIds, $ownIds) !== []) {
            throw new RuntimeException('That order no longer matches this workspace\'s models.');
        }

        DB::transaction(function () use ($data) {
            foreach ($data->orderedIds as $priority => $id) {
                AiProviderCredentialModel::query()->whereKey($id)->update(['priority' => $priority]);
            }
        });
    }
}
