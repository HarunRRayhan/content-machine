<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Console\Command;
use JsonException;

/**
 * Import personal-content PostSyncer JSON (workspaces.json, post_types.json)
 * and an API key into a Content Machine workspace's settings.
 */
class SeedPostsyncerSettingsCommand extends Command
{
    protected $signature = 'postsyncer:seed
                            {workspace_id : Content Machine workspace id}
                            {--workspaces= : Path to personal-content workspaces.json}
                            {--post-types= : Path to personal-content post_types.json}
                            {--api-key= : PostSyncer API key}';

    protected $description = 'Import PostSyncer workspace settings from personal-content JSON files';

    public function handle(): int
    {
        $workspace = Workspace::query()->find($this->argument('workspace_id'));

        if ($workspace === null) {
            $this->components->error("Workspace {$this->argument('workspace_id')} not found.");

            return self::FAILURE;
        }

        $workspacesPath = $this->option('workspaces');
        $postTypesPath = $this->option('post-types');
        $apiKey = $this->option('api-key');

        if (! is_string($workspacesPath) || $workspacesPath === '') {
            $this->components->error('The --workspaces option is required.');

            return self::FAILURE;
        }

        if (! is_string($postTypesPath) || $postTypesPath === '') {
            $this->components->error('The --post-types option is required.');

            return self::FAILURE;
        }

        if (! is_string($apiKey) || trim($apiKey) === '') {
            $this->components->error('The --api-key option is required.');

            return self::FAILURE;
        }

        foreach ([$workspacesPath, $postTypesPath] as $path) {
            if (! is_readable($path)) {
                $this->components->error("File not found: {$path}");

                return self::FAILURE;
            }
        }

        try {
            $workspaces = $this->readJson($workspacesPath);
            $postTypes = $this->readJson($postTypesPath);
        } catch (JsonException $exception) {
            $this->components->error('Invalid JSON: '.$exception->getMessage());

            return self::FAILURE;
        }

        PostsyncerConfig::write($workspace, [
            'api_key' => $apiKey,
            'publish_enabled' => true,
            'languages' => $this->mapLanguages($workspaces),
            'post_types' => $this->mapPostTypes($postTypes),
        ]);

        $this->components->info("PostSyncer settings imported for workspace {$workspace->id}.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new JsonException("Could not read {$path}");
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $workspaces
     * @return array<string, array{workspace_id: string, platforms: array<string, mixed>}>
     */
    private function mapLanguages(array $workspaces): array
    {
        $languages = [];

        foreach (['bangla', 'english'] as $language) {
            $entry = is_array($workspaces[$language] ?? null) ? $workspaces[$language] : [];
            $workspaceId = $entry['workspace_id'] ?? '';
            $platforms = is_array($entry['platforms'] ?? null) ? $entry['platforms'] : [];

            $languages[$language] = [
                'workspace_id' => is_scalar($workspaceId) ? (string) $workspaceId : '',
                'platforms' => $platforms,
            ];
        }

        return $languages;
    }

    /**
     * @param  array<string, mixed>  $postTypes
     * @return array<string, mixed>
     */
    private function mapPostTypes(array $postTypes): array
    {
        $platforms = is_array($postTypes['platforms'] ?? null) ? $postTypes['platforms'] : [];
        $overrides = is_array($postTypes['overrides'] ?? null) ? $postTypes['overrides'] : [];

        return [
            'platforms' => $platforms,
            'overrides' => $overrides,
        ];
    }
}
